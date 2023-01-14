<?php

namespace App\Services;

use GuzzleHttp\Client;

class GourmetAPIService
{
    private $api_key;
    public $clinet;

    public function __construct()
    {
        $this->api_key = $_ENV['RECRUIT_GRUMET_API_KEY'];
        $this->clinet = new Client(['base_uri' => 'http://webservice.recruit.co.jp/hotpepper/']);
    }

    /**
     * グルメAPIリクエスト
     *
     * @param $method
     * @param $query
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function request($method, $query = null): array
    {
        $path = "{$method}/v1";
        $options['query']['key'] = $this->api_key;
        if (!empty($query)) {
            foreach ($query as $key => $value) {
                $options['query'][$key] = $value;
            }
        }
        $response = $this->clinet->request('GET', $path, $options);
        $xml_data = simplexml_load_string($response->getBody()->getContents());

        return (json_decode(json_encode($xml_data), true));
    }

    /**
     * ジャンル一覧を取得
     *
     * @return \Illuminate\Support\Collection
     */
    public function getGenreList()
    {
        $data = $this->request('genre');
        return collect($data['genre']);
    }

    /**
     * 予算幅のコードを取得
     *
     * @param int $budget
     * @return mixed|string
     */
    public function getBudgetCode(int $budget)
    {
        $data = $xml_to_array_data = $this->request('budget');
        $budget_list = collect($data['budget'])->map(function ($v) {
            // 「円」の文字列を取り除き「～」をセパレータにして上限下限を数値で取得
            $budget_item = explode('～', str_replace('円', '', $v['name']));
            $budget_item[] = $v['code'];
            return $budget_item;
        });

        $code = '';
        foreach ($budget_list as $limit) {
            if (empty($limit[0]) && $budget <= $limit[1]) {
                $code = $limit[2];
                break;
            } else if (empty($limit[1]) && $budget > $limit[0]) {
                $code = $limit[2];
                break;
            } else if ($limit[0] < $budget && $budget <= $limit[1]) {
                $code = $limit[2];
                break;
            }
        }

        return $code;
    }

    /**
     * 候補店舗一覧取得
     *
     * @param string $location
     * @param string $genre
     * @param string $budget
     * @return \Illuminate\Support\Collection
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getCandidateStoreList(string $location, string $genre, string $budget)
    {
        $location = json_decode($location);
        $query = [
            'key' => $this->api_key,
            'lat' => $location->lat,
            'lng' => $location->lng,
            'genre' => $genre,
            'budget' => $budget,
            'count' => 10
        ];
        $xml_to_array_data = $this->request('gourmet', $query);

        if (!isset($xml_to_array_data['shop'])) {
            return collect([]);
        }

        return collect($xml_to_array_data['shop']);
    }
}
