<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LINE\LINEBot;
use LINE\LINEBot\Constant\HTTPHeader;
use LINE\LINEBot\Event\MessageEvent;
use LINE\LINEBot\Event\MessageEvent\LocationMessage;
use LINE\LINEBot\Event\MessageEvent\TextMessage;
use LINE\LINEBot\Event\PostbackEvent;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\CarouselColumnTemplateBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\CarouselTemplateBuilder;
use LINE\LINEBot\MessageBuilder\TemplateMessageBuilder;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;
use LINE\LINEBot\SignatureValidator;
use LINE\LINEBot\TemplateActionBuilder\PostbackTemplateActionBuilder;
use App\Models\Search;
use LINE\LINEBot\TemplateActionBuilder\UriTemplateActionBuilder;

class LINEBotService
{
    public CurlHTTPClient $httpClient;

    public LINEBot $bot;

    public GourmetAPIService $gourmet_api_service;

    private string $channel_secret;

    public function __construct()
    {
        $this->channel_secret = $_ENV['LINE_CHANNEL_SECRET'];
        $this->httpClient = new CurlHTTPClient($_ENV['LINE_CHANNEL_ACCESS_TOKEN']);
        $this->bot = new LINEBot($this->httpClient, ['channelSecret' => $this->channel_secret]);
        $this->gourmet_api_service = new GourmetAPIService();
    }

    public function eventHandler(Request $request): int
    {
        // 署名取得
        $signature = $request->headers->get(HTTPHeader::LINE_SIGNATURE);

        // 署名チェック
        $this->validateSignature($request, $signature);

        // リクエストをEventオブジェクトに変換する
        $events = $this->bot->parseEventRequest($request->getContent(), $signature);
        foreach ($events as $event) {
            // 返信先トークンを取得
            $replyToken = $event->getReplyToken();

            switch ($event) {
                // 位置情報取得時のメッセージ
                case $event instanceof LocationMessage:
                    $this->saveLocation($event);
                    $message_obj = $this->stepSelectGenre();
                    break;
                // ユーザーのアクションによって返却されるイベントオブジェクト
                case $event instanceof PostbackEvent:
                    $this->saveGenreCode($event);
                    $message_obj = new TextMessageBuilder('次に予算を入力してください。');
                    break;
                // テキストメッセージ
                case $event instanceof TextMessage:
                    $search = Search::where('uuid', $event->getUserId())->first();
                    if ($search->step === 2) {
                        // 予算幅のコードを登録する
                        $this->saveBudgetCode($event);

                        // 店舗を検索する
                        $search = Search::where('uuid', $event->getUserId())->first();
                        $store_list = $this->gourmet_api_service->getCandidateStoreList(
                            $search->location,
                            $search->genre,
                            $search->budget
                        );

                        // お店が1件もない場合
                        if ($store_list->isEmpty()) {
                            $message_obj = new TextMessageBuilder('お店が見つかりませんでした。');
                            $this->resetSearchInfo($event);
                            break;
                        }

                        // 候補店一覧を表示する
                        $message_obj = $this->stepDispStoreList($store_list);
                    } else {
                        $message_obj = new TextMessageBuilder('お店をお探しの場合は始めに位置情報を送信してください。');
                    }
                    break;
                default:
                    break;
            }
        }

        $response = $this->bot->replyMessage($replyToken, $message_obj);

        return $response->getHTTPStatus();
    }

    /**
     * 署名チェック
     *
     * @param Request $request
     * @param string $signature
     * @return void
     * @throws LINEBot\Exception\InvalidSignatureException
     */
    public function validateSignature(Request $request, string $signature): void
    {
        if (!$signature) {
            abort(400);
        }

        // LINEからのアクセスであるかチェック
        if (!SignatureValidator::validateSignature($request->getContent(), $this->channel_secret, $signature)) {
            abort(400);
        }
    }

    /**
     * ジャンルを選択する
     *
     * @return TemplateMessageBuilder
     */
    public function stepSelectGenre(): TemplateMessageBuilder
    {
        $genre_list = $this->gourmet_api_service->getGenreList();

        // テンプレートの制約上ボタン選択肢は4つが上限のため、絞り込んで取得
        $confirm_template_list = $genre_list->filter(function ($v, $i) {
            return $i !== 2 && $i < 5;
        })->map(function ($value) {
            return new PostbackTemplateActionBuilder(
                $value['name'],
                $value['code']
            );
        })->all();

        return new TemplateMessageBuilder(
            "希望ジャンル選択",
            new ButtonTemplateBuilder(
                null, // メッセージのタイトル
                "ご希望のジャンルを選択してください", // メッセージの内容
                null,
                $confirm_template_list
            )
        );
    }

    /**
     * 候補店舗一覧を表示する。
     *
     * @param mixed $store_list
     * @return TemplateMessageBuilder
     */
    public function stepDispStoreList(mixed $store_list): TemplateMessageBuilder
    {
        $columns = $store_list->map(function ($value) {
            $link = new UriTemplateActionBuilder('お店の詳細を確認する', $value['urls']['pc']);
            return new CarouselColumnTemplateBuilder(
                $value['name'],
                $value['address'],
                $value['logo_image'],
                [$link]
            );
        });

        return new TemplateMessageBuilder(
            "周辺の飲食店情報を表示",
            new CarouselTemplateBuilder($columns->all(), 'square')
        );
    }

    /**
     * 緯度、経度を保存
     *
     * @param LocationMessage $event
     * @return void
     */
    public function saveLocation(LocationMessage $event): void
    {
        $location = json_encode([
            'lat' => $event->getLatitude(),
            'lng' => $event->getLongitude()
        ]);

        $search = Search::where('uuid', $event->getUserId());
        if (!$search->exists()) {
            Search::create([
                'uuid' => $event->getUserId(),
                'step' => 1,
                'location' => $location
            ]);
        } else {
            $search->update([
                'step' => 1,
                'location' => $location,
            ]);
        }
    }

    /**
     * ジャンルコードを保存
     *
     * @param PostbackEvent $event
     * @return void
     */
    public function saveGenreCode(PostbackEvent $event): void
    {
        Search::where('uuid', $event->getUserId())
            ->update([
                'genre' => $event->getPostbackData(),
                'step' => 2
            ]);
    }

    /**
     * 予算幅コードを保存
     *
     * @param TextMessage $event
     * @return void
     */
    public function saveBudgetCode(TextMessage $event): void
    {
        $budget = intVal($event->getText());
        $code = $this->gourmet_api_service->getBudgetCode($budget);
        Search::where('uuid', $event->getUserId())
            ->update([
                'budget' => $code,
                'step' => 3
            ]);
    }

    /**
     * 検索情報をリセット
     *
     * @param MessageEvent $event
     * @return void
     */
    public function resetSearchInfo(MessageEvent $event): void
    {
        Search::where('uuid', $event->getUserId())
            ->update([
                'location' => null,
                'genre' => null,
                'budget' => null,
                'step' => 0
            ]);
    }
}
