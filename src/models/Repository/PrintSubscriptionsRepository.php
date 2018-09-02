<?php

namespace Crm\SubscriptionsModule\Repository;

use Crm\ApplicationModule\Repository;
use Crm\UsersModule\Repository\AddressesRepository;
use Crm\UsersModule\Repository\UsersRepository;
use DateTime;
use Nette\Caching\IStorage;
use Nette\Database\Context;
use Nette\Database\Table\IRow;

class PrintSubscriptionsRepository extends Repository
{
    protected $tableName = 'print_subscriptions';

    private $addressesRepository;

    private $usersRepository;

    public function __construct(
        Context $database,
        AddressesRepository $addressesRepository,
        UsersRepository $usersRepository,
        IStorage $cacheStorage = null
    ) {
        parent::__construct($database, $cacheStorage);
        $this->addressesRepository = $addressesRepository;
        $this->usersRepository = $usersRepository;
    }

    public function getAllCounts($type)
    {
        $allStatusCounts = $this->getTable()
            ->where(['type' => $type])
            ->select('exported_at, export_date, status, count(*) AS "total"')
            ->group('export_date, status')
            ->order('export_date DESC');

        $counts = [];
        foreach ($allStatusCounts as $row) {
            $printDate = $row->export_date->format('Y-m-d');
            if (!isset($counts[$printDate])) {
                $counts[$printDate] = [];
            }

            $counts[$printDate]['exported_at'] = $row->exported_at->format('Y-m-d');
            $counts[$printDate][$row->status] = $row->total;
        }
        return $counts;
    }

    public function add($type, $subscriptionsId, IRow $user, IRow $address = null, DateTime $exportDate = null, $status = 'new', $exportAt = null)
    {
        return $this->insert([
            'type' => $type,
            'subscription_id' => $subscriptionsId,
            'user_id' => $user->id,
            'exported_at' => $exportAt ? $exportAt : new DateTime(),
            'export_date' => $exportDate->format('Y-m-d'),
            'institution_name' => $user->institution_name,
            'first_name' => $address ? $address->first_name : null,
            'last_name' => $address ? $address->last_name : null,
            'address' => $address ? $address->address : null,
            'number' => $address ? $address->number : null,
            'zip' => $address ? $address->zip : null,
            'city' => $address ? $address->city : null,
            'phone_number' => $address ? $address->phone_number : null,
            'email' => $user->email,
            'status' => $status,
            'address_id' => $address ? $address->id : null,
        ]);
    }

    public function getTypes()
    {
        return $this->getTable()->group('type')->fetchPairs('type', 'type');
    }

    public function getExport($type, $date, $text = '', $status = '')
    {
        $where = ['type' => $type];
        if ($text != '') {
            $where['address LIKE ? OR first_name LIKE ? OR last_name LIKE ?'] = [
                "%{$text}%",
                "%{$text}%",
                "%{$text}%"
            ];
        }
        if ($status) {
            $where['status'] = $status;
        }
        $where['export_date'] = $date;
        return $this->getTable()->where($where)->order('status, first_name, last_name, address ASC');
    }

    public function getExportData($type, DateTime $date)
    {
        $where = [];
        $where['type'] = $type;
        $where['export_date'] = $date->format('Y-m-d');
        return $this->getTable()->where($where)->order('first_name, last_name, address ASC');
    }

    public function deleteList($type, $date)
    {
        $where = [
            'export_date' => $date,
            'type' => $type,
        ];
        return $this->getTable()->where($where)->delete();
    }

    public function setPrintExportStatus($type, DateTime $printExportDate, DateTime $exportAt)
    {
        $date = $this->getTable()->where(['type' => $type])->select('export_date')->group('export_date')->order('export_date DESC')->limit(1, 1);
        if (!isset($date[0])) {
            return;
        }
        $previousExportDate = $date[0]->export_date;
        $printExportDate->setTime(0, 0);

        $temp = $this->getTable()->select('user_id')
            ->where(['type' => $type])
            ->where('export_date', $previousExportDate)
            ->where('status != ?', 'removed');

        $this->getTable()
            ->where('user_id', $temp)
            ->where(['type' => $type])
            ->where('export_date', $printExportDate)
            ->update(['status' => 'recurrent']);

        $temp = $this->getTable()
            ->select('user_id')
            ->where(['type' => $type])
            ->where('export_date', $printExportDate);

        $printEnded = $this->getTable()
            ->select('*')
            ->where(['type' => $type])
            ->where('user_id NOT', $temp)
            ->where('export_date', $previousExportDate)
            ->where('status != ?', 'removed');
        foreach ($printEnded as $row) {
            $this->add($type, $row->subscription_id, $row->user, $row->addr, $printExportDate, 'removed', $exportAt);
        }
    }

    /**
     * Kopia funkcie hore
     *  - namiesto s user_id pracuje so subscription_id - co je asi spravne ale som sa bal to menit v tej hornej funkcii pre dennikn
     *
     */
    public function setPrintExportStatusTyzdenSpecial($type, DateTime $printExportDate, DateTime $exportAt)
    {
        $previousExportDate = clone $printExportDate;
        $previousExportDate->sub(new \DateInterval('P1D'));
        $previousExportDate->setTime(0, 0);

        $printExportDate->setTime(0, 0);

        $temp = $this->getTable()->select('subscription_id')
            ->where(['type' => $type])
            ->where('export_date', $previousExportDate)
            ->where('status != ?', 'removed');

        $this->getTable()
            ->where('subscription_id', $temp)
            ->where(['type' => $type])
            ->where('export_date', $printExportDate)
            ->update(['status' => 'recurrent']);

        $temp = $this->getTable()
            ->select('subscription_id')
            ->where(['type' => $type])
            ->where('export_date', $printExportDate);

        $printEnded = $this->getTable()
            ->select('*')
            ->where(['type' => $type])
            ->where('subscription_id NOT', $temp)
            ->where('export_date', $previousExportDate)
            ->where('status != ?', 'removed');
        foreach ($printEnded as $row) {
            $this->add($type, $row->subscription_id, $row->user, $row->addr, $printExportDate, 'removed', $exportAt);
        }
    }

    /**
     * Kopia funkcie hore
     * ble ble ble - meni fungovanie pridavanie expirovanych
     *
     */
    public function setPrintExportStatusTyzdenDaily($type, DateTime $printExportDate, DateTime $exportAt)
    {
        $previousExportDate = clone $printExportDate;
        $previousExportDate->sub(new \DateInterval('P1D'));
        $previousExportDate->setTime(0, 0);

        $printExportDate->setTime(0, 0);

        $temp = $this->getTable()->select('subscription_id')
            ->where(['type' => $type])
            ->where('export_date', $previousExportDate)
            ->where('status != ?', 'removed');

        $this->getTable()
            ->where('subscription_id', $temp)
            ->where(['type' => $type])
            ->where('export_date', $printExportDate)
            ->update(['status' => 'recurrent']);

        // nastavime na recurrent userov ktorym sa zmenilo predplatne (cize uz to neupdatla query vyssie) ale presli na dalsie
        // aktualne pri predlzeni ich to davalo stale ako new

        $oldDate = clone $printExportDate;
        $oldDate->sub(new \DateInterval('P8D'));



        $rows = $this->getDatabase()->query("
SELECT
  today.id,
  today.user_id AS today_user_id, today.subscription_id AS today_subscription_id ,
  yesterday.user_id AS yesterday_user_id, yesterday.subscription_id AS yesterday_subscription_id
FROM print_subscriptions AS today
INNER JOIN subscriptions AS today_subscription ON today_subscription.id = today.subscription_id
INNER JOIN print_subscriptions AS yesterday ON yesterday.export_date >= '{$oldDate->format('Y-m-d H:i:s')}' AND yesterday.export_date < '{$printExportDate->format('Y-m-d H:i:s')}' AND yesterday.user_id = today.user_id AND yesterday.type='{$type}'
INNER JOIN subscriptions AS yesterday_subscription ON yesterday_subscription.id = yesterday.subscription_id
WHERE today.export_date = '{$printExportDate->format('Y-m-d H:i:s')}' AND yesterday.status = 'recurrent' AND today.status = 'new' AND today.type='{$type}'
 -- vyradime tie ktore uz boli 'removed' aby ich nedalo znovu ako rekurentne lebo musia byt ako nove
 AND yesterday.subscription_id NOT IN (SELECT subscription_id FROM print_subscriptions WHERE subscription_id = yesterday.subscription_id AND status='removed')
GROUP BY today.id
");

        // zrusil som 'AND yesterday_subscription.next_subscription_id = today_subscription.id'
        foreach ($rows as $row) {
            echo "updatujem {$row->id} na recurrent\n";
            $this->getTable()->where(['id' => $row->id])->update(['status' => 'recurrent']);
        }
        
        $date = $printExportDate->format('Y-m-d 6:10');

//        $rows = $this->getDatabase()->query("SELECT end_subscription.*
//          FROM subscriptions AS end_subscription
//          INNER JOIN subscription_types AS end_subscription_type ON end_subscription_type.id = end_subscription.subscription_type_id AND end_subscription_type.print = 1
//          LEFT JOIN subscriptions AS next_subscription ON next_subscription.user_id = end_subscription.user_id AND next_subscription.end_time > end_subscription.end_time AND next_subscription.start_time < '{$date}' + INTERVAL 1 DAY
//          LEFT JOIN subscription_types AS next_subscription_type ON next_subscription_type.id = next_subscription.subscription_type_id AND next_subscription_type.print = 1
//          WHERE
//            end_subscription.end_time > '{$date}' - INTERVAL 1 DAY AND end_subscription.end_time < '{$date}'  AND
//            next_subscription_type.id IS NULL AND next_subscription.id IS NULL AND end_subscription.next_subscription_id IS NULL
//        ");

        $rows = $this->getDatabase()->query("SELECT end_subscription.*
          FROM subscriptions AS end_subscription
          INNER JOIN subscription_types AS end_subscription_type ON end_subscription_type.id = end_subscription.subscription_type_id AND end_subscription_type.print = 1
          WHERE
            end_subscription.end_time > '{$date}' - INTERVAL 1 DAY AND end_subscription.end_time < '{$date}' AND
            ( 
              SELECT subscriptions.id 
              FROM subscriptions 
              INNER JOIN subscription_types ON subscription_types.id = subscriptions.subscription_type_id AND subscription_types.print = 1
              WHERE subscriptions.end_time > end_subscription.end_time AND 
                    subscriptions.start_time < '{$date}' AND 
                    subscriptions.user_id = end_subscription.user_id
              LIMIT 1
            ) IS NULL
        ");

        foreach ($rows as $row) {
            $user = $this->usersRepository->find($row->user_id);
            $address = null;
            if ($row->address_id) {
                $address = $this->addressesRepository->find($row->address_id);
            }
            if (!$address) {
                $address = $this->addressesRepository->address($user, 'print');
            }
            if (!$address) {
                $address = null;
            }
            $this->add($type, $row->id, $user, $address, $printExportDate, 'removed', $exportAt);
        }
    }

    public function userPrintSubscriptions($userId)
    {
        return $this->getTable()->where(['user_id' => $userId])->order('export_date DESC');
    }
}
