<?php

namespace Crm\SubscriptionsModule\Presenters;

use Crm\AdminModule\Presenters\AdminPresenter;
use Crm\SubscriptionsModule\Components\SubscriptionEndsStatsFactoryInterface;
use Crm\SubscriptionsModule\Repository\ContentAccessRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Nette\Application\UI\Form;
use Nette\Utils\DateTime;
use Tomaj\Form\Renderer\BootstrapRenderer;

class SubscriptionsEndsPresenter extends AdminPresenter
{
    /** @var SubscriptionsRepository @inject */
    public $subscriptionsRepository;

    /** @var SubscriptionTypesRepository @inject */
    public $subscriptionTypesRepository;

    /** @var ContentAccessRepository @inject */
    public $contentAccessRepository;

    /** @persistent */
    public $startTime;

    /** @persistent */
    public $endTime;

    /** @persistent */
    public $withoutNext;

    /** @persistent */
    public $withoutRecurrent;

    /** @persistent */
    public $freeSubscriptions;

    /** @persistent */
    public $contentAccessTypes = [];

    public function startup()
    {
        parent::startup();

        $this->startTime = $this->startTime ?? DateTime::from(strtotime('-1 week'))->format('Y-m-d');
        $this->endTime = $this->endTime ?? (new DateTime())->format('Y-m-d');
    }

    public function renderDefault()
    {
        $subscriptions = $this->subscriptionsRepository->subscriptionsEndBetween(
            $this->startDateTime(),
            $this->endDateTime(),
            $this->withoutNext ? false : null
        );
        $subscriptions1 = $this->subscriptionsRepository->subscriptionsEndBetween(
            $this->startDateTime(),
            $this->endDateTime(),
            false
        );

        if (!$this->freeSubscriptions) {
            $subscriptions
                ->where('subscription_type.price > ?', 0)
                ->where('subscriptions.type NOT IN (?)', ['free']);
        }
        if ($this->withoutRecurrent) {
            $subscriptions->where('subscriptions.id NOT', $subscriptions1->where([
                ':payments:recurrent_payments.status' => null,
                ':payments:recurrent_payments.retries > ?' => 0,
                ':payments:recurrent_payments.state = ?' => 'active'
            ])->fetchPairs(null, 'id'));
        }

        if ($this->contentAccessTypes) {
            $subscriptions->where('subscription_type:subscription_type_content_access.content_access.name IN (?)', $this->contentAccessTypes);
        }

        $data = $subscriptions->fetchAll();
        $this->template->subscriptions = $data;
    }

    protected function createComponentSubscriptionEndsStats(SubscriptionEndsStatsFactoryInterface $factory)
    {
        $control = $factory->create();
        $control->setStartTime($this->startDateTime());
        $control->setEndTime($this->endDateTime());
        $control->setWithoutNext($this->withoutNext);
        $control->setWithoutRecurrent($this->withoutRecurrent);
        $control->setFreeSubscriptions($this->freeSubscriptions);
        return $control;
    }

    public function createComponentAdminFilterForm()
    {
        $form = new Form();
        $form->setTranslator($this->translator);
        $form->setRenderer(new BootstrapRenderer());
        $form->addText('startTime', 'subscriptions.data.subscriptions.fields.start_time')
            ->setAttribute('autofocus')
            ->setAttribute('class', 'flatpickr');
        $form->addText('endTime', 'subscriptions.data.subscriptions.fields.end_time')
            ->setAttribute('class', 'flatpickr');
        $form->addCheckbox('withoutNext', 'subscriptions.admin.subscriptions_ends.default.without_next');
        $form->addCheckbox('withoutRecurrent', 'subscriptions.admin.subscriptions_ends.default.without_recurrent');
        $form->addCheckbox('freeSubscriptions', 'subscriptions.admin.subscriptions_ends.default.free_subscriptions');

        $form->addMultiSelect('contentAccessTypes', 'subscriptions.admin.subscription_end_stats.content_access_types', $this->contentAccessRepository->all()->fetchPairs('name', 'name'))
            ->getControlPrototype()->addAttributes(['class' => 'select2']);

        $form->addSubmit('send', 'system.filter')
            ->getControlPrototype()
            ->setName('button')
            ->setHtml('<i class="fa fa-filter"></i> ' . $this->translator->translate('system.filter'));

        $presenter = $this;
        $form->addSubmit('cancel', 'system.cancel_filter')->onClick[] = function () use ($presenter) {
            $presenter->redirect('default', ['text' => '']);
        };
        $form->onSuccess[] = [$this, 'adminFilterSubmited'];
        $form->setDefaults((array) $this->params);
        return $form;
    }

    public function adminFilterSubmited($form, $values)
    {
        $this->redirect('default', (array) $values);
    }

    private function startDateTime(): DateTime
    {
        return DateTime::from($this->startTime . ' 00:00:00');
    }

    private function endDateTime(): DateTime
    {
        return DateTime::from($this->endTime . ' 23:59:59');
    }
}
