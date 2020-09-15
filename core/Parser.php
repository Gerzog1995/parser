<?php

namespace ParserCore;

use ParserCore\interfaces\IParser;

require_once 'core/library/simple_html_dom.php';
require_once 'config.php';

/**
 * Class Parser
 * @package ParserCore
 */
class Parser implements IParser
{

    /**
     * Initialize and get information
     * @param string $argv
     * @return mixed
     */
    public function getInitInfo(string $argv)
    {
        $start = microtime(true);
        if ($curl = curl_init(URL_START_PAGE_PARSE)) {
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_COOKIEJAR, DIRNAME_COOKIES);
            if ($csrf = $this->getCsrf(curl_exec($curl))) {
                if ($url = $this->getUrlFormData($csrf, $argv)) {
                    curl_setopt($curl, CURLOPT_URL, URL_SEND_REQUEST_TO_FILTER);
                    curl_setopt($curl, CURLOPT_POST, true);
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $url);
                    curl_setopt($curl, CURLOPT_COOKIEJAR, DIRNAME_COOKIES);
                    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
                    curl_setopt($curl, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
                    curl_setopt($curl, CURLOPT_PROXY, PROXY);
                    $out = curl_exec($curl);
                    if (!curl_errno($curl)) {
                        $html = str_get_html($out);
                        if ($count = $this->getNumberLastPageOfPagination($html)) {
                            if ($token_result = $this->getTokenOfResult($html)) {
                                if ($result = $this->getListInfoOfParse($count, $token_result)) {
                                    $time = microtime(true) - $start;
                                    curl_close($curl);
                                    print_r($result);
                                    echo 'Затраченное время(млс): ' . $time . PHP_EOL;
                                    exit;
                                }
                                throw new \Exception('Not found list of parse');
                            }
                            throw new \Exception('Not found "token_result"');
                        }
                        throw new \Exception('Not found number lasted page from pagination');
                    }
                    throw new \Exception(curl_error($curl));
                }
                throw new \Exception('Not found url-signature');
            }
            throw new \Exception('Not found Csrf-token.');
        }
    }

    /**
     * Getting link from the FormData header
     * @param string $csrf
     * @param string $argv
     * @return string
     */
    private function getUrlFormData(string $csrf, string $argv): string
    {
        $url = "_csrf={$csrf}&wv%5B0%5D={$argv}&wt%5B0%5D=PART&weOp%5B0%5D=AND&wv%5B1%5D=&wt%5B1%5D=PART&wrOp=AND&wv%5B2%5D=&wt%5B2%5D=PART&weOp%5B1%5D=AND&wv%5B3%5D=&wt%5B3%5D=PART&iv%5B0%5D=&it%5B0%5D=PART&ieOp%5B0%5D=AND&iv%5B1%5D=&it%5B1%5D=PART&irOp=AND&iv%5B2%5D=&it%5B2%5D=PART&ieOp%5B1%5D=AND&iv%5B3%5D=&it%5B3%5D=PART&wp=&_sw=on&classList=&ct=A&status=&dateType=LODGEMENT_DATE&fromDate=&toDate=&ia=&gsd=&endo=&nameField%5B0%5D=OWNER&name%5B0%5D=&attorney=&oAcn=&idList=&ir=&publicationFromDate=&publicationToDate=&i=&c=&originalSegment=";
        return $url;
    }

    /**
     * Getting the last page number from pagination
     * @param object $html
     * @return int
     */
    private function getNumberLastPageOfPagination(object $html): int
    {
        return $html->find("a.goto-last-page", 0)->getAttribute('data-gotopage') ?: 0;
    }

    /**
     * Getting a token for a subquery
     * @param object $html
     * @return string
     */
    private function getTokenOfResult($html): string
    {
        return $html->find("input[name=s]", 0)->value ?: strval(0);
    }

    /**
     * Getting Csrf token
     * @param string $out
     * @return string
     */
    private function getCsrf($out): string
    {
        $html = str_get_html($out);
        $csrf = strval(0);
        foreach ($html->find("#basicSearchForm input[name=_csrf]") as $element) {
            $csrf = $element->value;
        }
        return $csrf;
    }

    /**
     * Setting item in last iteration of fork in shmop
     * @param array $childPids
     * @return array
     */
    private function setItemLastAtShmop(array $childPids): array
    {
        $arrayRuntime = [];
        foreach ($childPids as $childPid) {
            pcntl_waitpid($childPid, $status);
            $sharedId = shmop_open($childPid, 'a', 0, 0);
            $shareData = shmop_read($sharedId, 0, shmop_size($sharedId));
            $arrayRuntime = $arrayRuntime ? array_merge($arrayRuntime, json_decode($shareData, 1)) : json_decode($shareData, 1);
            shmop_delete($sharedId);
            shmop_close($sharedId);
        }
        return $arrayRuntime;
    }

    /**
     * Getting a list of parsing information for each page
     * @param array $childPids
     * @return array
     */
    private function getListInfoParseOfPage(string $url): array
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $out = curl_exec($curl);
        $html = str_get_html($out);
        $arrayRuntime = [];
        foreach ($html->find("#resultsTable tbody tr") as $element) {
            $arrayRuntime[] = [
                'number' => $element->children(2)->plaintext,
                'logo_url' => $element->find('img', 0) ? $element->find('img', 0)->getAttribute('src') : '',
                'name' => $element->find('.trademark.words', 0)->plaintext,
                'classes' => $element->find('.classes', 0)->plaintext,
                'status' => $element->find('.status div span', 0)->plaintext,
                'details_page_url' => DOMAIN."/trademarks/search/view/" . trim($element->children(2)->plaintext),
            ];
        }
        curl_close($curl);
        return $arrayRuntime;
    }

    /**
     *  Getting list of information from data parsing
     * @param int $count
     * @param string $token_result
     * @return array
     */
    private function getListInfoOfParse(int $count, string $token_result): array
    {
        $childPids = [];
        for ($i = 0; $i <= $count; $i++) {
            $pid = pcntl_fork();
            if ($pid == -1) {
                echo 'Fork -1';
                exit;
            } elseif ($pid) {
                $childPids[] = $pid;
                if ($i == $count) {
                    return $this->setItemLastAtShmop($childPids);
                }
            } else {
                $myPid = getmypid();
                $array = $this->getListInfoParseOfPage(DOMAIN.'/trademarks/search/result?s=' . $token_result . '&p=' . $i);
                $sharedId = shmop_open($myPid, 'c', 0644, strlen(json_encode($array)));
                shmop_write($sharedId, json_encode($array), 0);
                exit(0);
            }
        }
    }

}

