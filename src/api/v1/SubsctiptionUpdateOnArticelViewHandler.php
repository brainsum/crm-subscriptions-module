<?php

namespace Crm\SubscriptionsModule\Api\v1;

use Crm\ApiModule\Api\ApiHandler;
use Crm\ApiModule\Api\JsonResponse;
use Crm\ApiModule\Authorization\ApiAuthorizationInterface;
use Crm\ApiModule\Params\InputParam;
use Crm\ApiModule\Params\ParamsProcessor;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Nette\Http\Response;
use Nette\Utils\DateTime;

class SubsctiptionUpdateOnArticelViewHandler extends ApiHandler
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
            new InputParam(InputParam::TYPE_POST, 'url', InputParam::REQUIRED),
        ];
    }

    public function handle(ApiAuthorizationInterface $authorization) {
        $data = $authorization->getAuthorizedData();
        if (!isset($data['token'])) {
            $response = new JsonResponse(['status' => 'error', 'message' => 'Cannot authorize user']);
            $response->setHttpCode(Response::S403_FORBIDDEN);
            return $response;
        }

        $paramsProcessor = new ParamsProcessor($this->params());
        $params = $paramsProcessor->getValues();

        $token = $data['token'];
        $subscription = $this->getActiveSubscription($token->user_id);

        if ($subscription) {
          $result = $this->countView($subscription, $params['url']);
        } else {
          $result = [
              'status' => 'no_active_subscription',
          ];
        }

        $response = new JsonResponse($result);
        $response->setHttpCode(Response::S200_OK);
        return $response;
    }

    private function getActiveSubscription($user_id) {
        $subscriptions = $this->subscriptionsRepository->userSubscriptions($user_id);
        $where = [
          'end_time >= ?' => new DateTime(),
          'start_time <= ?' => new DateTime()
        ];
        $subscriptions->where($where);

        $countSub = false;
        $timeSub = false;

        foreach ($subscriptions as $sub) {

          $sub_type = $sub->subscription_type;
          if ($sub_type->code == 'article_count') {
            $countSub = $sub;
          } else {
            $timeSub = $sub;
          }
        }

        return $timeSub ? $timeSub : ($countSub ? $countSub : false);
    }

    private function countView($subscription, $url) {
        $sub_type = $subscription->subscription_type;
        $values = $subscription->toArray();
        if ($sub_type->code == 'article_count') {
            $viewed_articles = unserialize($values['articles'], ['allowed_classes' => ['array']]);

            if (!is_array($viewed_articles)) {
                $viewed_articles = [];
            }

            if (in_array($url, $viewed_articles)) {
                return [
                    'status' => 'ok',
                    'remaining' => $sub_type->length - count($viewed_articles)
                ];
            }

            if (count($viewed_articles) < $sub_type->length) {
                $viewed_articles[] = $url;
                $values['articles'] = serialize(array_filter($viewed_articles));
                $this->subscriptionsRepository->update($subscription, $values);
                return [
                    'status' => 'ok',
                    'remaining' => $sub_type->length - count($viewed_articles)
                ];
            }
            return [
                'status' => 'not more articles',
                'remaining' => $sub_type->length - count($viewed_articles)
            ];
        }
        return [
            'status' => 'ok',
        ];
    }

}
