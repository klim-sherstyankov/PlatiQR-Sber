<?php

declare (strict_types = 1);

namespace App\Service;

use App\Entity\Application;
use Doctrine\ORM\EntityManagerInterface;

class SberQrPayment
{
    const URL_TOKEN_AUTHORIZATION = 'https://api.sberbank.ru/ru/prod/tokens/v2/oauth';
    const URL_ORDER_CREATE        = 'https://api.sberbank.ru/prod/qr/order/v3/creation';
    const URL_ORDER_STATUS        = 'https://api.sberbank.ru/prod/qr/order/v3/status';
    const URL_ORDER_REVOKE        = 'https://api.sberbank.ru/prod/qr/order/v3/revocation';
    const URL_ORDER_CANCEL        = 'https://api.sberbank.ru/prod/qr/order/v3/cancel';
    const URL_ORDER_REGISTRY      = 'https://api.sberbank.ru/prod/qr/order/v3/registry';
    const SCOPES                  = [
        'create'   => 'https://api.sberbank.ru/order.create',
        'status'   => 'https://api.sberbank.ru/order.status',
        'revoke'   => 'https://api.sberbank.ru/qr/order.revoke',
        'cancel'   => 'https://api.sberbank.ru/qr/order.cancel',
        'registry' => 'auth://qr/order.registry',
    ];

    private $clientId;
    private $clientToken;
    private $qrId;

    /**
     * @param EntityManagerInterface $manager
     */
    public function __construct(EntityManagerInterface $manager)
    {
        $this->manager     = $manager;
        $this->clientId    = 'xxxx';
        $this->clientToken = 'xxxx';
        $this->qrId        = 'xxxx';
    }

    /**
     * Создание заказа и подготовка к отправке
     * @param  int    $applicationId [номер заказа]
     */
    public function createOrder(int $applicationId)
    {
        $application = $this->manager->getRepository(Application::class)->findOneById($applicationId);
        $items       = [];
        $sum         = 0;
        foreach ($application->getApplicationProducts() as $i => $item) {
            $price   = (int) round($item->getTariffPrice() * 100);
            $items[] = [
                'position_name'        => $item->getName(),
                'position_count'       => ['value' => 1, 'measure' => 'шт'],
                'position_sum'         => $price,
                'position_description' => '',
            ];
            $sum += $price;
        }

        $rqUid = $this->getRandomString();

        $headers = [
            'accept: application/json',
            'authorization: Bearer '.$this->getToken(self::SCOPES['create']),
            'content-type: application/json',
            'x-ibm-client-id: '.$this->$clientId,
            'x-Introspect-RqUID: '.$rqUid,
        ];

        $date = new \DateTime();
        $date = $date->format('Y-m-d').'T'.$date->format('h:i:s').'Z';

        $order = [
            'rq_uid'            => $rqUid,
            'rq_tm'             => $date,
            'member_id'         => (string) $order->getId(),
            'order_number'      => $application->calcPaymentId(),
            'order_create_date' => $application->getCreatedAt()->format('Y-m-d').'T'.$application->getCreatedAt()->format('h:i:s').'Z',
            'order_params_type' => $items,
            'id_qr'             => $this->qrId,
            'order_sum'         => $sum,
            'currency'          => 'RUB',
            'description'       => 'Номер заказа: '.$application->calcPaymentId(),
        ];

        $response = $this->sendCurl(self::URL_API_ORDER_CREATE, $headers, $order, 'POST');

        return $response;
    }

    /**
     * Отправка запроса
     * @param  string $url        [адресная строка запроса]
     * @param  array  $headers    [заголовки]
     * @param  array  $postFields [тело запроса]
     * @param  string $type       [тип запроса]
     */
    private function sendCurl(
        string $url,
        array $headers,
        array $postFields,
        ? string $type = 'GET'
    ) {
        $curl = curl_init();

        $curlOptions = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => 'POST' == $type ? json_encode($postFields) : http_build_query($postFields),
            CURLOPT_HTTPHEADER     => $headers,
        ];

        curl_setopt_array($curl, $curlOptions);
        $response = curl_exec($curl);
        $result   = json_decode($response, true);
        curl_close($curl);

        return $result;
    }

    /**
     * Проверка статуса заказа
     * @param  int $orderId
     */
    public function getOrderStatus(int $orderId)
    {
        $rq_uid = $this->getRandomString();

        $headers = [
            'accept: application/json',
            'authorization: Bearer '.$this->getToken(self::SCOPES['status']),
            'content-type: application/json',
            'x-ibm-client-id: '.$this->$clientId,
            'x-Introspect-RqUID: '.$rq_uid,
        ];
        $date  = new DateTime();
        $date  = $date->format('Y-m-d').'T'.$date->format('h:i:s').'Z';
        $order = [
            'rq_uid'   => $rq_uid,
            'rq_tm'    => $date,
            'order_id' => $orderId,
        ];

        $response = $this->sendCurl(self::URL_API_ORDER_STATUS, $headers, $order, 'POST');

        return $response;
    }

    /**
     * Получение токена
     * @param  string $scope [тип url]
     * @return string        [полученный токен]
     */
    public function getToken(string $scope) : string
    {
        $headers = [
            'accept: application/json',
            'authorization: '.$this->getAuthorizationHeader(),
            'content-type: application/x-www-form-urlencoded',
            'rquid: '.$this->getRandomString(),
            'x-ibm-client-id: '.$this->$clientId,
        ];

        $postFields = [
            'grant_type' => 'client_credentials',
            'scope'      => $scope,
        ];

        $response = $this->sendCurl(self::URL_TOKEN_AUTHORIZATION, $headers, $postFields);

        return $response['access_token'];
    }

    /**
     * Формирование header запроса
     * @return string [header]
     */
    private function getAuthorizationHeader(): string
    {
        return 'Basic '.base64_encode($this->clientId.':'.$this->clientToken);
    }

    /**
     * Получение рандомной строки
     * @param  int $length [колличество знаков в строке]
     * @return string              [рандомная строка]
     */
    public static function getRandomString(int $length = 25): string
    {
        $random_string = str_pad(md5(date('c')), $length, rand());

        return $random_string;
    }
}
