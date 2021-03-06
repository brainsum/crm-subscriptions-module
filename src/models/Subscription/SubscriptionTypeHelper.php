<?php

namespace Crm\SubscriptionsModule\Subscription;

use Crm\ApplicationModule\Helpers\PriceHelper;
use Nette\Database\Table\ActiveRow;

class SubscriptionTypeHelper
{
    private $priceHelper;

    public function __construct(PriceHelper $priceHelper)
    {
        $this->priceHelper = $priceHelper;
    }

    public function getPairs($subscriptionTypes, $allowHtml = false): array
    {
        $subscriptionTypePairs = [];
        foreach ($subscriptionTypes as $st) {
            $price = $this->priceHelper->getFormattedPrice($st->price);
            $subscriptionTypePairs[$st->id] = html_entity_decode(sprintf(
                "%s / %s %s",
                $st->name,
                $price,
                $allowHtml ? "<small>({$st->code})</small>" : "({$st->code})"
            ));
        }
        return $subscriptionTypePairs;
    }

    public function getItems($subscriptionTypes): array
    {
        $subscriptionPairs = [];
        /** @var ActiveRow $st */
        foreach ($subscriptionTypes as $st) {
            $subscriptionPairs[$st->id] = [
                'price' => $st->price,
                'items' => [],
            ];
            foreach ($st->related('subscription_type_items') as $item) {
                $subscriptionPairs[$st->id]['items'][] = [
                    'name' => $item->name,
                    'amount' => $item->amount,
                    'vat' => $item->vat,
                    'meta' => array_merge($item->related('subscription_type_item_meta')->fetchPairs('key', 'value'), ['subscription_type_item_id' => $item->id])
                ];
            }
        }
        return $subscriptionPairs;
    }
}
