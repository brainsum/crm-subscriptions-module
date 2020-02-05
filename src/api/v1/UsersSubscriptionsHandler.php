<?php

namespace Crm\SubscriptionsModule\Api\v1;

use Crm\ApiModule\Api\ApiHandler;
use Crm\ApiModule\Api\JsonResponse;
use Crm\ApiModule\Authorization\ApiAuthorizationInterface;
use Crm\ApiModule\Params\InputParam;
use Crm\ApiModule\Params\ParamsProcessor;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\UsersModule\Auth\UsersApiAuthorizationInterface;
use Nette\Http\Response;
use Nette\Utils\DateTime;

class UsersSubscriptionsHandler extends ApiHandler
{
    private $subscriptionsRepository;

    public function __construct(
        SubscriptionsRepository $subscriptionsRepository
    ) {
        $this->subscriptionsRepository = $subscriptionsRepository;
    }

    public function params()
    {
        return [
            new InputParam(InputParam::TYPE_GET, 'show_finished', InputParam::OPTIONAL),
        ];
    }

    public function handle(ApiAuthorizationInterface $authorization)
    {
        if (!($authorization instanceof UsersApiAuthorizationInterface)) {
            throw new \Exception("Wrong authorization service used. Should be 'UsersApiAuthorizationInterface'");
        }

        $paramsProcessor = new ParamsProcessor($this->params());
        if ($paramsProcessor->isError()) {
            $response = new JsonResponse(['status' => 'error', 'code' => 'invalid_request', 'message' => $paramsProcessor->isError()]);
            $response->setHttpCode(Response::S400_BAD_REQUEST);
            return $response;
        }
        $params = $paramsProcessor->getValues();
        $authorizedUsers = $authorization->getAuthorizedUsers();

        $where = ['end_time >= ?' => new DateTime()];
        if (isset($params['show_finished']) && in_array($params['show_finished'], ['1', 'true'])) {
            $where = [];
        }

        $subscriptions = [];
        foreach ($authorizedUsers as $authorizedUser) {
            $subscriptions[] = $this->subscriptionsRepository->userSubscriptions($authorizedUser->id)->where($where)->fetchAll();
        }
        $subscriptions = array_merge([], ...$subscriptions);
        usort($subscriptions, function ($a, $b) {
            return $a->end_time < $b->end_time;
        });

        $result = [
            'status' => 'ok',
            'subscriptions' => [],
        ];

        foreach ($subscriptions as $subscription) {
            $subscriptionType = $subscription->subscription_type;
            $result['subscriptions'][] = $this->formatSubscription($subscription, $subscriptionType);
        }

        $response = new JsonResponse($result);
        $response->setHttpCode(Response::S200_OK);
        return $response;
    }

    private function formatSubscription($subscription, $subscriptionType)
    {
        $access = [];

        $formatted_subscription = [
            'start_at' => $subscription->start_time->format('c'),
            'end_at' => $subscription->end_time->format('c'),
            'code' => $subscriptionType->code,
            'access' => []
        ];

        foreach ($subscriptionType->related('content_access')->order('content_access.sorting') as $contentAccess) {
            $formatted_subscription['access'][] = $contentAccess->content_access->name;
            if ($contentAccess->content_access->name == 'articles') {
                $viewed_articles = unserialize($subscription->articles);
                $formatted_subscription['remaining_articles'] = max(0, $subscriptionType->length - count($viewed_articles));
            }
        }

        return $formatted_subscription;
    }
}
