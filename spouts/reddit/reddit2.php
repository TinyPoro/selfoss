<?php

namespace spouts\reddit;

use GuzzleHttp\Url;
use helpers\WebClient;
use Stringy\Stringy as S;

/**
 * Spout for fetching from reddit
 *
 * @copyright  Copyright (c) Tobias Zeising (http://www.aditu.de)
 * @license    GPLv3 (https://www.gnu.org/licenses/gpl-3.0.html)
 * @author     Tobias Zeising <tobias.zeising@aditu.de>
 */
class reddit2 extends \spouts\spout {
    /** @var string name of spout */
    public $name = 'Reddit';

    /** @var string description of this source type */
    public $description = 'Get your fix from Reddit';

    /**
     * config params
     * array of arrays with name, type, default value, required, validation type
     *
     * - Values for type: text, password, checkbox, select
     * - Values for validation: alpha, email, numeric, int, alnum, notempty
     *
     * When type is "select", a new entry "values" must be supplied, holding
     * key/value pairs of internal names (key) and displayed labels (value).
     * See /spouts/rss/heise for an example.
     *
     * e.g.
     * array(
     *   "id" => array(
     *     "title"      => "URL",
     *     "type"       => "text",
     *     "default"    => "",
     *     "required"   => true,
     *     "validation" => array("alnum")
     *   ),
     *   ....
     * )
     *
     * @var bool|mixed
     */
    public $params = [
        'url' => [
            'title' => 'Subreddit or multireddit url',
            'type' => 'text',
            'default' => 'r/worldnews/top',
            'required' => true,
            'validation' => ['notempty']
        ],
        'username' => [
            'title' => 'Username',
            'type' => 'text',
            'default' => '',
            'required' => false,
            'validation' => ''
        ],
        'password' => [
            'title' => 'Password',
            'type' => 'password',
            'default' => '',
            'required' => false,
            'validation' => ''
        ]
    ];

    /** @var string the reddit_session cookie */
    private $reddit_session = '';

    /** @var array|null current fetched items */
    protected $items = null;

    /** @var string favicon url */
    private $faviconUrl = '';

    /**
     * loads content for given source
     *
     * @param array  $params
     *
     * @throws \GuzzleHttp\Exception\RequestException When an error is encountered
     * @throws \RuntimeException if the response body is not in JSON format
     *
     * @return void
     */
    public function load($params) {
        if (!empty($params['password']) && !empty($params['username'])) {
            if (function_exists('apc_fetch')) {
                $this->reddit_session = apc_fetch("{$params['username']}_selfoss_reddit_session");
                if (empty($this->reddit_session)) {
                    $this->login($params);
                }
            } else {
                $this->login($params);
            }
        }

        // ensure the URL is absolute
        $url = Url::fromString('https://www.reddit.com/')->combine($params['url']);
        // and that the path ends with .json (Reddit does not seem to recogize Accept header)
        $url->setPath(S::create($url->getPath())->ensureRight('.json'));

        $response = $this->sendRequest($url);
        $json = $response->json();

        if (isset($json['error'])) {
            throw new \Exception($json['message']);
        }

        $this->items = $json['data']['children'];
    }

    //
    // Iterator Interface
    //

    /**
     * reset iterator
     *
     * @return void
     */
    public function rewind() {
        if ($this->items !== null) {
            reset($this->items);
        }
    }

    /**
     * receive current item
     *
     * @return \SimplePie_Item current item
     */
    public function current() {
        if ($this->items !== null) {
            return $this;
        }

        return false;
    }

    /**
     * receive key of current item
     *
     * @return mixed key of current item
     */
    public function key() {
        if ($this->items !== null) {
            return key($this->items);
        }

        return null;
    }

    /**
     * select next item
     *
     * @return \SimplePie_Item next item
     */
    public function next() {
        if ($this->items !== null) {
            next($this->items);
        }

        return $this;
    }

    /**
     * end reached
     *
     * @return bool false if end reached
     */
    public function valid() {
        if ($this->items !== null) {
            return current($this->items) !== false;
        }

        return false;
    }

    /**
     * returns an unique id for this item
     *
     * @return string id as hash
     */
    public function getId() {
        if ($this->items !== null && $this->valid()) {
            $id = @current($this->items)['data']['id'];
            if (strlen($id) > 255) {
                $id = md5($id);
            }

            return $id;
        }

        return false;
    }

    /**
     * returns the current title as string
     *
     * @return string title
     */
    public function getTitle() {
        if ($this->items !== null && $this->valid()) {
            return @current($this->items)['data']['title'];
        }

        return false;
    }

    /**
     * returns the current title as string
     *
     * @throws \GuzzleHttp\Exception\RequestException When an error is encountered
     *
     * @return string title
     */
    public function getHtmlUrl() {
        if ($this->items !== null && $this->valid()) {
            return @current($this->items)['data']['url'];
        }

        return false;
    }

    /**
     * returns the content of this item
     *
     * @throws \GuzzleHttp\Exception\RequestException When an error is encountered
     *
     * @return string content
     */
    public function getContent() {
        if ($this->items !== null && $this->valid()) {
            $data = @current($this->items)['data'];
            $text = $data['selftext_html'];
            if (!empty($text)) {
                return htmlspecialchars_decode($text);
            }

            if (isset($data['preview']) && isset($data['preview']['images'])) {
                $text = '';
                foreach ($data['preview']['images'] as $image) {
                    if (isset($image['source']) && isset($image['source']['url'])) {
                        $text .= '<img src="' . $image['source']['url'] . '">';
                    }
                }

                if ($text !== '') {
                    return $text;
                }
            }

            if (preg_match('/\.(?:gif|jpg|png|svg)$/i', Url::fromString($this->getHtmlUrl())->getPath())) {
                return '<img src="' . $this->getHtmlUrl() . '" />';
            }

            return $data['url'];
        }

        return false;
    }

    /**
     * returns the icon of this item
     *
     * @return string icon url
     */
    public function getIcon() {
        $imageHelper = $this->getImageHelper();
        $htmlUrl = $this->getHtmlUrl();
        if ($htmlUrl && $imageHelper->fetchFavicon($htmlUrl)) {
            $this->faviconUrl = $imageHelper->getFaviconUrl();
        }

        return $this->faviconUrl;
    }

    /**
     * returns the link of this item
     *
     * @return string link
     */
    public function getLink() {
        if ($this->items !== null && $this->valid()) {
            return 'https://www.reddit.com' . @current($this->items)['data']['permalink'];
        }

        return false;
    }

    /**
     * returns the thumbnail of this item
     *
     * @return string thumbnail url
     */
    public function getThumbnail() {
        if ($this->items !== null && $this->valid()) {
            return @current($this->items)['data']['thumbnail'];
        }

        return false;
    }

    /**
     * returns the date of this item
     *
     * @return string date
     */
    public function getdate() {
        if ($this->items !== null && $this->valid()) {
            $date = date('Y-m-d H:i:s', @current($this->items)['data']['created_utc']);
        }

        return $date;
    }

    /**
     * destroy the plugin (prevent memory issues)
     */
    public function destroy() {
        unset($this->items);
        $this->items = null;
    }

    /**
     * returns the xml feed url for the source
     *
     * @param mixed $params params for the source
     *
     * @return string url as xml
     */
    public function getXmlUrl($params) {
        return  'reddit://' . urlencode($params['url']);
    }

    /**
     * @throws \GuzzleHttp\Exception\RequestException When an error is encountered
     * @throws \RuntimeException if the response body is not in JSON format
     * @throws \Exception if the credentials are invalid
     */
    private function login($params) {
        $http = WebClient::getHttpClient();
        $response = $http->post("https://ssl.reddit.com/api/login/{$params['username']}", [
            'body' => [
                'api_type' => 'json',
                'user' => $params['username'],
                'passwd' => $params['password']
            ]
        ]);
        $data = $response->json();
        if (count($data['json']['errors']) > 0) {
            $errors = '';
            foreach ($data['json']['errors'] as $error) {
                $errors .= $error[1] . PHP_EOL;
            }
            throw new \Exception($errors);
        } else {
            $this->reddit_session = $data['json']['data']['cookie'];
            if (function_exists('apc_store')) {
                apc_store("{$params['username']}_selfoss_reddit_session", $this->reddit_session, 3600);
            }
        }
    }

    /**
     * Send a HTTP request to given URL, possibly with a cookie.
     *
     * @param string $url
     * @param string $method
     *
     * @throws \GuzzleHttp\Exception\RequestException When an error is encountered
     *
     * @return \GuzzleHttp\Message\Response
     */
    private function sendRequest($url, $method = 'GET') {
        $http = WebClient::getHttpClient();

        if (isset($this->reddit_session)) {
            $request = $http->createRequest($method, $url, [
                'cookies' => ['reddit_session' => $this->reddit_session]
            ]);
        } else {
            $request = $http->createRequest($method, $url);
        }

        $response = $http->send($request);

        return $response;
    }
}
