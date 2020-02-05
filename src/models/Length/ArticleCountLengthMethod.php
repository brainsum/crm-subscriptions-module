<?php

namespace Crm\SubscriptionsModule\Length;

use DateTime;
use DateInterval;
use Nette\Database\Table\IRow;
use Crm\SubscriptionsModule\Length\Length;
use Crm\SubscriptionsModule\Length\LengthMethodInterface;

class ArticleCountLengthMethod implements LengthMethodInterface
{
    public function getEndTime(DateTime $startTime, IRow $subscriptionType, bool $isExtending): Length
    {
        // This type of subscription should last indefinitely,
        // it ends when the user consumed the set number of articles.
        $length = 99999;
        if ($isExtending && $subscriptionType->extending_length) {
            $length = $subscriptionType->extending_length;
        }
        $interval = new DateInterval("P{$length}D");
        $end = (clone $startTime)->add($interval);

        if ($subscriptionType->fixed_end) {
            $end = $subscriptionType->fixed_end;
        }

        return new Length($end, $length);
    }
}
