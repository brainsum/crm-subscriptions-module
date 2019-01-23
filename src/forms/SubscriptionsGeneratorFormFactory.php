<?php

namespace Crm\SubscriptionsModule\Forms;

use Crm\SubscriptionsModule\Generator\SubscriptionsGenerator;
use Crm\SubscriptionsModule\Generator\SubscriptionsParams;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Subscription\SubscriptionType;
use Crm\UsersModule\Auth\UserManager;
use Crm\UsersModule\Email\EmailValidator;
use DateInterval;
use Kdyby\Translation\Phrase;
use Kdyby\Translation\Translator;
use Nette\Application\UI\Form;
use Nette\Utils\DateTime;
use Tomaj\Form\Renderer\BootstrapRenderer;

class SubscriptionsGeneratorFormFactory
{
    const NEWLY_REGISTERED = 'newly_registered';
    const SKIPPED = 'skipped';
    const INACTIVE = 'inactive';
    const ACTIVE = 'active';

    private $userManager;

    private $subscriptionTypesRepository;

    private $subscriptionsGenerator;

    private $translator;

    private $subscriptionsRepository;

    private $emailValidator;

    public $onSubmit;

    public $onCreate;

    public function __construct(
        UserManager $userManager,
        SubscriptionsGenerator $subscriptionsGenerator,
        SubscriptionTypesRepository $subscriptionTypesRepository,
        Translator $translator,
        SubscriptionsRepository $subscriptionsRepository,
        EmailValidator $emailValidator
    ) {
        $this->userManager = $userManager;
        $this->subscriptionsGenerator = $subscriptionsGenerator;
        $this->subscriptionTypesRepository = $subscriptionTypesRepository;
        $this->translator = $translator;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->emailValidator = $emailValidator;
    }

    /**
     * @return Form
     */
    public function create()
    {
        $defaults = [
            'type' => SubscriptionsRepository::TYPE_FREE,
            'create_users' => true,
            'user_groups' => [
                self::NEWLY_REGISTERED,
                self::INACTIVE,
            ],
        ];

        $form = new Form;

        $form->setRenderer(new BootstrapRenderer());
        $form->setTranslator($this->translator);
        $form->addProtection();

        $form->addGroup('subscriptions.menu.subscriptions');

        $subscriptionTypePairs = SubscriptionType::getPairs($this->subscriptionTypesRepository->getAllActive());
        $subscriptionType = $form->addSelect('subscription_type','subscriptions.admin.subscription_generator.field.subscription_type', $subscriptionTypePairs)
            ->setPrompt("subscriptions.admin.subscription_generator.prompt.subscription_type")
            ->setRequired("subscriptions.admin.subscription_generator.required.subscription_type");
        $subscriptionType->getControlPrototype()->addAttributes(['class' => 'select2']);

        $form->addText('start_time', 'subscriptions.data.subscriptions.fields.start_time')
            ->setAttribute('placeholder', 'subscriptions.data.subscriptions.placeholder.start_time')
            ->setOption('description', 'subscriptions.admin.subscription_generator.description.start_time')
            ->setAttribute('class', 'flatpickr');

        $form->addText('end_time', 'subscriptions.data.subscriptions.fields.end_time')
            ->setAttribute('placeholder', 'subscriptions.data.subscriptions.placeholder.end_time')
            ->setOption('description', 'subscriptions.admin.subscription_generator.description.end_time')
            ->setAttribute('class', 'flatpickr');

        $form->addSelect('type', 'subscriptions.data.subscriptions.fields.type', $this->subscriptionsRepository->availableTypes())
            ->setOption('description', 'subscriptions.admin.subscription_generator.description.type');

        $form->addGroup('subscriptions.admin.subscription_generator.group.users');

        $form->addTextArea('emails', 'subscriptions.admin.subscription_generator.field.emails')
            ->setAttribute('rows', 20)
            ->setRequired('subscriptions.admin.subscription_generator.required.emails')
            ->setAttribute('placeholder', 'subscriptions.admin.subscription_generator.placeholder.emails')
            ->setOption('description', 'subscriptions.admin.subscription_generator.description.emails');

        $form->addCheckbox('create_users', 'subscriptions.admin.subscription_generator.field.create_users')
            ->setOption('description', 'subscriptions.admin.subscription_generator.description.create_users');

        $form->addCheckboxList('user_groups', 'subscriptions.admin.subscription_generator.field.user_groups', [
            'newly_registered' => 'subscriptions.admin.subscription_generator.field.newly_registered',
            'inactive' => 'subscriptions.admin.subscription_generator.field.inactive',
            'active' => 'subscriptions.admin.subscription_generator.field.active',
        ])
            ->setOption('description', 'subscriptions.admin.subscription_generator.description.user_groups');

        $form->addCheckbox('generate', 'subscriptions.admin.subscription_generator.form.generate')
            ->setOption('description', 'subscriptions.admin.subscription_generator.description.generate');

        $form->addSubmit('submit', 'subscriptions.admin.subscription_generator.form.send')
            ->getControlPrototype()
            ->setName('button')
            ->setHtml('<i class="fa fa-save"></i> ' . $this->translator->translate('subscriptions.admin.subscription_generator.form.send'));

        $form->setDefaults($defaults);

        $form->onSuccess[] = [$this, 'formSucceeded'];

        return $form;
    }

    public function formSucceeded(Form $form, $values)
    {
        $subscriptionType = $this->subscriptionTypesRepository->find($values['subscription_type']);

        $startTime = DateTime::from(strtotime($values['start_time']));
        $endTime = DateTime::from(strtotime($values['end_time']));
        if (!$values['end_time']) {
            $endTime = clone $startTime;
            $endTime->add(new DateInterval("P{$subscriptionType->length}D"));
        }

        $stats = [
            self::NEWLY_REGISTERED => 0,
            self::INACTIVE => 0,
            self::ACTIVE => 0,
            self::SKIPPED => 0,
        ];

        $emails = [];
        foreach (explode("\n", $values->emails) as $email) {
            $email = trim($email);
            if (empty($email)) {
                continue;
            }
            if (!$this->emailValidator->isValid($email)) {
                $form['emails']->addError(new Phrase('subscriptions.admin.subscription_generator.errors.invalid_email', null, ['email' => $email]));
            }
            if (!empty($email)) {
                $emails[] = $email;
            }
        }

        foreach ($emails as $email) {
            $user = $this->userManager->loadUserByEmail($email);

            // newly registered
            if (!$user) {
                if (!$values->create_users) {
                    // user doesn't exist and we don't want create new users
                    continue;
                }

                $user = $this->userManager->addNewUser($email, true, 'subscriptiongenerator', null, false);

                if (!in_array(self::NEWLY_REGISTERED, $values->user_groups)) {
                    $stats[self::SKIPPED] += 1;
                    // we don't want to create subscription for newly registered, halting here
                    continue;
                }

                if ($values->generate) {
                    $this->subscriptionsGenerator->generate(
                        new SubscriptionsParams(
                            $subscriptionType,
                            $user,
                            $values['type'],
                            $startTime,
                            $endTime),
                        1
                    );
                }
                $stats[self::NEWLY_REGISTERED] += 1;

                // newly registered scenario handled completely
                continue;
            }

            // already registered
            $actualSubscription = $this->subscriptionsRepository->actualUserSubscription($user->id);

            if ($actualSubscription && !in_array(self::ACTIVE, $values->user_groups)) {
                // we don't want to create subscription for active subscribers, halting here
                $stats[self::SKIPPED] += 1;
                continue;
            }
            if (!$actualSubscription && !in_array(self::INACTIVE, $values->user_groups)) {
                // we don't want to create subscription for inactive subscribers, halting here
                $stats[self::SKIPPED] += 1;
                continue;
            }

            $actualSubscription ? $stats[self::ACTIVE] += 1 : $stats[self::INACTIVE] += 1;;

            if ($values->generate) {
                $this->subscriptionsGenerator->generate(
                    new SubscriptionsParams(
                        $subscriptionType,
                        $user,
                        $values['type'],
                        $startTime,
                        $endTime),
                    1
                );
            }
        }

        $messages = [];
        $type = $values->generate ? 'info' : 'warning';
        $messages += [
            [
                'text' => $this->translator->translate('subscriptions.admin.subscription_generator.messages.registered', $stats[self::NEWLY_REGISTERED]),
                'type' => $type
            ],
            [
                'text' => $this->translator->translate('subscriptions.admin.subscription_generator.messages.inactive', $stats[self::INACTIVE]),
                'type' => $type
            ],
            [
                'text' => $this->translator->translate('subscriptions.admin.subscription_generator.messages.active', $stats[self::ACTIVE]),
                'type' => $type,
            ],
            [
                'text' => $this->translator->translate('subscriptions.admin.subscription_generator.messages.skipped', $stats[self::SKIPPED]),
                'type' => 'warning',
            ],
        ];
        ($this->onSubmit)($messages);
    }
}
