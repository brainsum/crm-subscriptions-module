<?php

namespace Crm\SubscriptionsModule\Forms;

use Crm\ApplicationModule\DataProvider\DataProviderManager;
use Crm\SubscriptionsModule\DataProvider\SubscriptionFormDataProviderInterface;
use Crm\SubscriptionsModule\Events\SubscriptionPreUpdateEvent;
use Crm\SubscriptionsModule\Length\LengthMethodFactory;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Subscription\SubscriptionTypeHelper;
use Crm\UsersModule\Repository\AddressesRepository;
use Crm\UsersModule\Repository\UsersRepository;
use Kdyby\Translation\Translator;
use League\Event\Emitter;
use Nette\Application\UI\Form;
use Nette\Database\IRow;
use Nette\Utils\DateTime;
use Tomaj\Form\Renderer\BootstrapRenderer;

class SubscriptionFormFactory
{
    private $dataProviderManager;

    private $subscriptionsRepository;

    private $subscriptionTypesRepository;

    private $usersRepository;

    private $addressesRepository;

    private $lengthMethodFactory;

    private $translator;

    private $emitter;

    private $hermesEmitter;

    private $subscriptionTypeHelper;

    public $onSave;

    public $onUpdate;

    public function __construct(
        DataProviderManager $dataProviderManager,
        SubscriptionsRepository $subscriptionsRepository,
        SubscriptionTypesRepository $subscriptionTypesRepository,
        UsersRepository $usersRepository,
        AddressesRepository $addressesRepository,
        LengthMethodFactory $lengthMethodFactory,
        Translator $translator,
        Emitter $emitter,
        \Tomaj\Hermes\Emitter $hermesEmitter,
        SubscriptionTypeHelper $subscriptionTypeHelper
    ) {
        $this->dataProviderManager = $dataProviderManager;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->subscriptionTypesRepository = $subscriptionTypesRepository;
        $this->usersRepository = $usersRepository;
        $this->addressesRepository = $addressesRepository;
        $this->lengthMethodFactory = $lengthMethodFactory;
        $this->translator = $translator;
        $this->emitter = $emitter;
        $this->hermesEmitter = $hermesEmitter;
        $this->subscriptionTypeHelper = $subscriptionTypeHelper;
    }

    /**
     * @param IRow $user
     * @param int|null $subscriptionId
     * @return Form
     */
    public function create(IRow $user, int $subscriptionId = null)
    {
        $defaults = [];
        $subscription = false;
        if ($subscriptionId != null) {
            $subscription = $this->subscriptionsRepository->find($subscriptionId);
            $defaults = $subscription->toArray();
        }

        $article_list = isset($defaults['articles']) ? unserialize($defaults['articles'], ['allowed_classes' => ['array']]) : [];

        $defaults['articles'] = is_array($article_list) ? implode("\n", $article_list) : "";

        $form = new Form;

        $form->setRenderer(new BootstrapRenderer());
        $form->setTranslator($this->translator);
        $form->addProtection();

        $active = $subscriptionTypePairs = $this->subscriptionTypeHelper->getPairs(
            $this->subscriptionTypesRepository->all()->where(['active' => true]),
            true
        );
        $noActive = $subscriptionTypePairs = $this->subscriptionTypeHelper->getPairs(
            $this->subscriptionTypesRepository->all()->where(['active' => false]),
            true
        );

        $subscriptionTypeId = $form->addSelect(
            'subscription_type_id',
            'subscriptions.data.subscriptions.fields.subscription_type',
            $active + [0 => '--'] + $noActive
        )->setRequired();
        $subscriptionTypeId->getControlPrototype()->addAttributes(['class' => 'select2']);

        if (!$subscription) {
            $subscriptionTypeId->addRule(function ($field, IRow $user) {
                $subscriptionType = $this->subscriptionTypesRepository->find($field->value);
                if (!empty($subscriptionType->limit_per_user) &&
                    $this->subscriptionsRepository->getCount($subscriptionType->id, $user->id) >= $subscriptionType->limit_per_user) {
                    return false;
                }

                return true;
            }, 'subscriptions.data.subscriptions.required.subscription_type_id', $user);
        }

        $subscriptionTypeNames = $this->subscriptionsRepository->activeSubscriptionTypes()->fetchPairs('type', 'type');
        if ($subscription && !in_array($subscription->type, $subscriptionTypeNames)) {
            $subscriptionTypeNames[$subscription->type] = $subscription->type;
        }
        $form->addSelect('type', 'subscriptions.data.subscriptions.fields.type', $subscriptionTypeNames);

        $form->addCheckbox('is_paid', 'subscriptions.data.subscriptions.fields.is_paid');

        $form->addText('start_time', 'subscriptions.data.subscriptions.fields.start_time')
            ->setRequired('subscriptions.data.subscriptions.required.start_time')
            ->setAttribute('placeholder', 'subscriptions.data.subscriptions.placeholder.start_time')
            ->setAttribute('class', 'flatpickr')
            ->setAttribute('flatpickr_datetime_seconds', "1");

        $form->addText('end_time', 'subscriptions.data.subscriptions.fields.end_time')
            ->setAttribute('placeholder', 'subscriptions.data.subscriptions.placeholder.end_time')
            ->setOption('description', 'subscriptions.data.subscriptions.description.end_time')
            ->setAttribute('class', 'flatpickr')
            ->setAttribute('flatpickr_datetime_seconds', "1");

        $form->addTextArea('note', 'subscriptions.data.subscriptions.fields.note')
            ->setAttribute('placeholder', 'subscriptions.data.subscriptions.placeholder.note')
            ->getControlPrototype()
            ->addAttributes(['class' => 'autosize']);

        $form->addTextArea('articles', 'subscriptions.data.subscriptions.fields.articles')
            ->setAttribute('placeholder', 'subscriptions.data.subscriptions.placeholder.articles')
            ->getControlPrototype()
            ->addAttributes(['class' => 'autosize']);

        $form->addSelect('address_id', 'subscriptions.data.subscriptions.fields.address_id', $this->addressesRepository->addressesSelect($user, false))
            ->setPrompt('--');

        $form->addHidden('user_id', $user->id);
        if ($subscriptionId) {
            $form->addHidden('subscription_id', $subscriptionId);
        }

        $providers = $this->dataProviderManager->getProviders(
            SubscriptionFormDataProviderInterface::PATH,
            SubscriptionFormDataProviderInterface::class
        );
        foreach ($providers as $sorting => $provider) {
            $form = $provider->provide(['form' => $form]);
        }

        $form->addSubmit('send', 'system.save')
            ->getControlPrototype()
            ->setName('button')
            ->setHtml('<i class="fa fa-save"></i> ' . $this->translator->translate('system.save'));

        $form->setDefaults($defaults);

        $form->onSuccess[] = [$this, 'formSucceeded'];

        return $form;
    }

    public function formSucceeded(Form $form, $values)
    {
        if ($values['end_time'] == "") {
            $values['end_time'] = null;
        }

        if ($values['end_time'] && strtotime($values['end_time']) <= strtotime($values['start_time'])) {
            $form['end_time']->addError($this->translator->translate('subscriptions.data.subscriptions.errors.end_time_before_start_time'));
            return;
        }

        $startTime = DateTime::from(strtotime($values['start_time']));
        $endTime = null;
        if ($values['end_time']) {
            $endTime = DateTime::from(strtotime($values['end_time']));
        }

        if ($values['subscription_type_id'] == 0) {
            $form['subscription_type_id']->addError($this->translator->translate('subscriptions.data.subscriptions.errors.no_subscription_type_id'));
            return;
        }

        $subscriptionType = $this->subscriptionTypesRepository->find($values['subscription_type_id']);
        $user = $this->usersRepository->find($values['user_id']);

        $article_list = preg_split('/\r\n|\r|\n/', $values['articles']);

        $articles = serialize($article_list);
        $values['articles'] = $articles;

        if (isset($values['subscription_id'])) {
            $subscriptionId = $values['subscription_id'];
            unset($values['subscription_id']);

            $values['start_time'] = $startTime;
            $values['end_time'] = $endTime;
            $values['internal_status'] = SubscriptionsRepository::INTERNAL_STATUS_UNKNOWN;

            $subscription = $this->subscriptionsRepository->find($subscriptionId);

            if ($values['start_time'] && !$values['end_time']) {
                $lengthMethod = $this->lengthMethodFactory->getExtension($subscriptionType->length_method_id);
                $length = $lengthMethod->getEndTime($values['start_time'], $subscriptionType, false);
                $values['end_time'] = $length->getEndTime();
            }

            if ($form->hasErrors()) {
                return;
            }

            $this->emitter->emit(new SubscriptionPreUpdateEvent($subscription, $form, $values));
            $this->subscriptionsRepository->update($subscription, $values);
            $this->onUpdate->__invoke($subscription);
        } else {
            $address = null;
            if ($values->address_id) {
                $address = $this->addressesRepository->find($values->address_id);
            }

            $subscription = $this->subscriptionsRepository->add(
                $subscriptionType,
                false,
                $values['is_paid'],
                $user,
                $values['type'],
                $startTime,
                $endTime,
                $values['note'],
                $address,
                $articles
            );
            $this->onSave->__invoke($subscription);
        }
    }
}
