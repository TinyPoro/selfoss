<?php
/**
 * Created by PhpStorm.
 * User: TinyPoro
 * Date: 11/27/18
 * Time: 3:59 PM
 */

namespace app\api;

use GuzzleHttp\Client;

class ApiConnector
{
    private $start_url = 'http://c2.toppick.vn/wp-json';

    private $client;

    public function __construct()
    {
        $this->client = new Client();
    }

    private $total_pages = 1;

    public function getCategories(){
        $total_categories = [];

        $categories = $this->getCategoriesByPage(1);
        $total_categories = array_merge($total_categories, $categories);

        for ( $i = 2; $i <= $this->total_pages; $i++){
            $categories = $this->getCategoriesByPage($i);
            $total_categories = array_merge($total_categories, $categories);
        }

        foreach ($total_categories as $category){

            $category->rules = $this->getRuleByCategory($category->id);
        }

        return $total_categories;
    }

    private function getCategoriesByPage($page){
        $response = $this->client->get($this->start_url."/wp/v2/categories?page=$page");

        $categories = json_decode($response->getBody()->getContents());

        $categories = array_filter($categories, function ( $category ){
           return $category->parent == 0;
        });

        if($page == 1) $this->total_pages = $response->getHeader('X-WP-TotalPages');

        return $categories;
    }

    private function getRuleByCategory($category_id){
        $total_rules = [];

        $total_rules = array_merge($total_rules, $this->getRuleByCategoryAndEndPoint("/toppick/v1/rules/$category_id"));

        //to do feed rule

        return $total_rules;
    }

    private function getRuleByCategoryAndEndPoint($end_point, $type = 'normal'){
        $response = $this->client->get($this->start_url."$end_point");

        $rules = json_decode($response->getBody()->getContents());

        foreach ($rules as $rule){
            $rule->type = $type;
        }

        return $rules;
    }
}