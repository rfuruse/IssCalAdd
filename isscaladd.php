#!/usr/bin/php
<?php

// クラスをロード
require_once 'Zend/Loader.php';
Zend_Loader::loadClass('Zend_Config_Ini');
Zend_Loader::loadClass('Zend_Gdata');
Zend_Loader::loadClass('Zend_Gdata_ClientLogin');
Zend_Loader::loadClass('Zend_Gdata_Calendar');
Zend_Loader::loadClass('Zend_Http_Client');
Zend_Loader::loadClass('Zend_Dom_Query');

mb_internal_encoding('UTF-8');
//mb_http_input('UTF-8');
mb_http_output('UTF-8');

class IssCalAdd
{
    // config情報
    private $_config = null;
    // カレンダー接続オブジェクト
    private $_gDataCal = null;


    /**
     * 設定ファイルを読込
     */
    public function __construct()
    {
        $this->_config = new Zend_Config_Ini('isscaladd.ini', 'global');
    }


    /**
     * 処理実行
     */
    public function run()
    {
        // 情報元から通過日時アイテム取得
        $data = self::_getSource();
        // 対象期間のイベントを削除
        self::_gCalDelEvent($data['start'], $data['end']);

        foreach ($data['items'] as $item) {
            // コンテンツ生成
            $contents = '';
            foreach ($item as $key => $value) {
                // epochタイムなどは含めない
                if ($key == 'startDatetime' || $key == 'endDatetime' || $key == 'link')
                    continue;
                // 各要素をセパレータにて接続
                $contents .= $key.' : '.$value."\n";
            }
            // イベントを追加
            self::_gCalAddEvent($this->_config->config->event, $item['startDatetime'], $item['endDatetime'], $contents, $item['link']);
        }
    }


    /**
     * 指定URLからアイテム(通過時刻、角度など)を取得
     */
    private function _getSource()
    {
        // 取得した値のキーテーブル
        $keys = array('Date', 'Brightness[Mag]',
                'Start Time', 'Start Alt.', 'Start Az.',
                'Highest Time', 'Highest Alt.', 'Highest Az.',
                'End Time', 'End Alt.', 'End Az.', 'type');

        // 戻り値をクリア
        $result = array();

        // 指定URLを取得
        $client = new Zend_Http_Client($this->_config->config->url);
        $client->setHeaders('Accept-Language', 'ja,en-US;');
        $resp = $client->request();

        // HTMLをDOMオブジェクトとして取得
        $dom = new Zend_Dom_Query($resp->getBody());

        // 表示中の表を取得
        $table = $dom->query('table.standardTable td');

        // 開始時刻/終了時刻の退避エリア
        $times = array();

        // 各通過日時のkey-value配列
        $values = array();

        // DOMよりelement取得
        foreach ($table as $key => $item) {
            // ヘッダ行は無視
            if ($key < 16)
                continue;

            switch (($key - 15) % 12) {
            case 0:    // Pass type [行末]
                // 開始時刻/終了時刻をepochに変換
                $values['startDatetime'] = strtotime($values['Date'].', '.$values['Start Time']);
                $values['endDatetime'] = strtotime($values['Date'].', '.$values['End Time']);

                unset($values['Date']);

                // 開始時刻/終了時刻を退避
                $times[] = $values['startDatetime'];
                $times[] = $values['endDatetime'];

                // ISS通過時間
                $values['Duration'] = $values['endDatetime'] - $values['startDatetime'].'sec';

                // 戻り値配列に追加
                $result[] = $values;

                // 各通過日時の配列クリア
                $values = array();
                break;
            case 1:    // Date
                $values[$keys[($key - 16) % 12]] = $item->nodeValue;

                foreach ($item->childNodes as $tags) {
                    $values['link'] = $tags->getAttribute('href');
                }
                break;
            case 4:    // Start Alt.
            case 7:    // Highest Alt.
            case 10:   // End Alt.
                preg_match('/^([0-9]+)/', $item->nodeValue, $tmp);

                $values[$keys[($key - 16) % 12]] = $tmp[0].'°';
                break;
            default:
                // key-valueにて保持
                $values[$keys[($key - 16) % 12]] = $item->nodeValue;
            }
        }

        //
        return array('start' => min($times), 'end' => max($times), 'items' => $result);
    }


    /**
     * googleへ接続
     */
    private function _gCalConnect()
    {
        // 未接続だったら
        if ($this->_gDataCal == null) {
            // サービスに接続
            $client = Zend_Gdata_ClientLogin::getHttpClient($this->_config->config->user, $this->_config->config->passwd, Zend_Gdata_Calendar::AUTH_SERVICE_NAME);
            // 接続オブジェクトをメンバ変数に退避
            $this->_gDataCal = new Zend_Gdata_Calendar($client);
        }
    }


    /**
     * イベント追加
     */
    private function _gCalAddEvent( $title, $startDatetime, $endDatetime, $content, $url )
    {
        self::_gCalConnect();

        //日付時刻をATOM形式に
        $start = date(DATE_ATOM, $startDatetime);
        $end = date(DATE_ATOM, $endDatetime);

        //イベントを作成し追加
        try {
            // 新規イベントを生成
            $event = $this->_gDataCal->newEventEntry();
            // タイトルを設定
            $event->title = $this->_gDataCal->newTitle($title);
            // 期間を設定
            $when = $this->_gDataCal->newWhen();
            $when->startTime = $start;
            $when->endTime = $end;
            $event->when = array($when);
            // コンテンツを設定
            $event->content = $this->_gDataCal->newContent($content);
            // LINKを設定
            $event->link = array($this->_gDataCal->newLink('http://www.heavens-above.com/'.$url, 'related', 'text/html'));
            // 指定カレンダにイベント追加
            $this->_gDataCal->insertEvent($event, 'https://www.google.com/calendar/feeds/'.$this->_config->config->calender.'/private/full');
        } catch (Zend_Gdata_App_Exception $e) {
            echo $e->getResponse();
        }
    }


    /**
     * 登録済みイベントを削除
     */
    private function _gCalDelEvent( $startDatetime, $endDatetime )
    {
        self::_gCalConnect();

        //日付時刻をATOM形式に
        $start = date(DATE_ATOM, $startDatetime);
        $end = date(DATE_ATOM, $endDatetime);

        $query = $this->_gDataCal->newEventQuery();
        $query->setUser($this->_config->config->calender);
        $query->setVisibility('private');
        $query->setProjection('full');
        $query->setOrderby('starttime');
        $query->setStartMin($start);
        $query->setStartMax($end);
        $eventFeed = $this->_gDataCal->getCalendarEventFeed($query);

        // 既存対象イベントを削除
        foreach ($eventFeed as $event) {
            // タイトルが同一の場合
            if ($event->title == $this->_config->config->event)
                $event->delete();
        }
    }
}

$app = new IssCalAdd();
$app->run();
