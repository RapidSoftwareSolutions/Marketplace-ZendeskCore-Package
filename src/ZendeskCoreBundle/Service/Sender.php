<?php
/**
 * Created by PhpStorm.
 * User: rapidapi
 * Date: 14.04.17
 * Time: 13:56
 */

namespace ZendeskCoreBundle\Service;


use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Psr\Http\Message\ResponseInterface;

class Sender
{
    public function send($url, $method, $data, $headers, $type) {
        try {
            $client = new Client();
            /** @var ResponseInterface $vendorResponse */
            $vendorResponse = $client->$method($url, [
                'headers' => $headers,
                $type => $this->prepareData($data, $type)
            ]);
            if (in_array($vendorResponse->getStatusCode(), range(200, 204))) {
                $result['callback'] = 'success';
                $vendorResponseBodyContent = $vendorResponse->getBody()->getContents();
                if (empty(trim($vendorResponseBodyContent))) {
                    $result['contextWrites']['to'] = $vendorResponse->getReasonPhrase();
                } else {
                    $result['contextWrites']['to'] = json_decode($vendorResponseBodyContent, true);
                }
            } else {
                $result['callback'] = 'error';
                $result['contextWrites']['to']['status_code'] = 'API_ERROR';
                $result['contextWrites']['to']['status_msg'] = is_array($vendorResponse) ? $vendorResponse : json_decode($vendorResponse, true);
            }
        } catch (BadResponseException $exception) {
            // todo add params, to find in header needed to response
            $exceptionResponseContent = $exception->getResponse()->getBody()->getContents();
            $result['callback'] = 'error';
            $result['contextWrites']['to']['status_code'] = 'API_ERROR';
            if (empty(trim($exceptionResponseContent))) {
                $result['contextWrites']['to']['status_msg'] = $exception->getResponse()->getReasonPhrase();
            } else {
                $result['contextWrites']['to']['status_msg'] = json_decode($exceptionResponseContent, true);
            }
        }

        return $result;
    }

    /**
     * Return data formated to type (multipart fix)
     * @param array  $data
     * @param string $type
     * @return array
     */
    private function prepareData(array $data, string $type):array {
        if (mb_strtolower($type) == 'multipart') {
            $result = [];
            foreach ($data as $key => $value) {
                $result[] = [
                    "name" => $key,
                    "contents" => $value
                ];
            }
            return $result;
        }

        return $data;
    }
}