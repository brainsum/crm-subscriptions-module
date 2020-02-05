<?php

namespace Crm\SubscriptionsModule;

use Crm\ApiModule\Api\ApiRoutersContainerInterface;
use Crm\ApiModule\Router\ApiIdentifier;
use Crm\ApiModule\Router\ApiRoute;
use Crm\ApplicationModule\Access\AccessManager;
use Crm\ApplicationModule\Commands\CommandsContainerInterface;
use Crm\ApplicationModule\Criteria\CriteriaStorage;
use Crm\ApplicationModule\Criteria\ScenariosCriteriaStorage;
use Crm\ApplicationModule\CrmModule;
use Crm\ApplicationModule\DataProvider\DataProviderManager;
use Crm\ApplicationModule\Event\EventsStorage;
use Crm\ApplicationModule\Menu\MenuContainerInterface;
use Crm\ApplicationModule\Menu\MenuItem;
use Crm\ApplicationModule\SeederManager;
use Crm\ApplicationModule\User\UserDataRegistrator;
use Crm\ApplicationModule\Widget\WidgetManagerInterface;
use Crm\SubscriptionsModule\DataProvider\CanDeleteAddressDataProvider;
use Crm\SubscriptionsModule\DataProvider\FilterAbusiveUserFormDataProvider;
use Crm\SubscriptionsModule\DataProvider\SubscriptionsClaimUserDataProvider;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Scenarios\ContentAccessCriteria;
use Crm\SubscriptionsModule\Scenarios\HasDisabledNotificationsCriteria;
use Crm\SubscriptionsModule\Scenarios\IsRecurrentCriteria;
use Crm\SubscriptionsModule\Scenarios\SubscriptionTypeCriteria;
use Crm\SubscriptionsModule\Scenarios\SubscriptionTypeLengthCriteria;
use Crm\SubscriptionsModule\Scenarios\TypeCriteria;
use Crm\SubscriptionsModule\Seeders\ConfigSeeder;
use Crm\SubscriptionsModule\Seeders\ContentAccessSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionExtensionMethodsSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionLengthMethodSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionTypeNamesSeeder;
use Kdyby\Translation\Translator;
use League\Event\Emitter;
use Nette\Application\Routers\Route;
use Nette\Application\Routers\RouteList;
use Nette\DI\Container;
use Symfony\Component\Console\Output\OutputInterface;
use Tomaj\Hermes\Dispatcher;

class SubscriptionsModule extends CrmModule
{
    private $subscriptionsRepository;

    public function __construct(Container $container, Translator $translator, SubscriptionsRepository $subscriptionsRepository)
    {
        parent::__construct($container, $translator);
        $this->subscriptionsRepository = $subscriptionsRepository;
    }

    public function registerAdminMenuItems(MenuContainerInterface $menuContainer)
    {
        $mainMenu = new MenuItem(
            $this->translator->translate('subscriptions.menu.subscriptions'),
            '#subscriptions',
            'fa fa-shopping-cart',
            500
        );

        $menuItem1 = new MenuItem(
            $this->translator->translate('subscriptions.menu.subscription_types'),
            ':Subscriptions:SubscriptionTypesAdmin:default',
            'fa fa-magic',
            600
        );

        $menuItem2 = new MenuItem(
            $this->translator->translate('subscriptions.menu.subscriptions_generator'),
            ':Subscriptions:SubscriptionsGenerator:default',
            'fa fa-copy',
            700
        );

        $mainMenu->addChild($menuItem1);
        $mainMenu->addChild($menuItem2);

        $menuContainer->attachMenuItem($mainMenu);

        // dashboard menu item

        $menuItem = new MenuItem(
            $this->translator->translate('subscriptions.menu.stats'),
            ':Subscriptions:Dashboard:default',
            'fa fa-shopping-cart',
            400
        );
        $menuContainer->attachMenuItemToForeignModule('#dashboard', $mainMenu, $menuItem);

        $menuItem = new MenuItem(
            $this->translator->translate('subscriptions.menu.endings'),
            ':Subscriptions:Dashboard:endings',
            'fa fa-frown fa-fw',
            500
        );
        $menuContainer->attachMenuItemToForeignModule('#dashboard', $mainMenu, $menuItem);
    }

    public function registerFrontendMenuItems(MenuContainerInterface $menuContainer)
    {
        $menuItem = new MenuItem($this->translator->translate('subscriptions.menu.subscriptions'), ':Subscriptions:Subscriptions:my', '', 5);
        $menuContainer->attachMenuItem($menuItem);
    }

    public function registerWidgets(WidgetManagerInterface $widgetManager)
    {
        $widgetManager->registerWidget(
            'admin.user.detail.bottom',
            $this->getInstance(\Crm\SubscriptionsModule\Components\UserSubscriptionsListing::class),
            100
        );
        $widgetManager->registerWidget(
            'admin.payments.listing.action',
            $this->getInstance(\Crm\SubscriptionsModule\Components\SubscriptionButton::class),
            6000
        );
        $widgetManager->registerWidget(
            'admin.user.detail.box',
            $this->getInstance(\Crm\SubscriptionsModule\Components\ActualUserSubscriptions::class),
            300
        );
        $widgetManager->registerWidget(
            'dashboard.singlestat.totals',
            $this->getInstance(\Crm\SubscriptionsModule\Components\TotalSubscriptionsStatWidget::class),
            600
        );
        $widgetManager->registerWidget(
            'dashboard.singlestat.actuals.subscribers',
            $this->getInstance(\Crm\SubscriptionsModule\Components\ActualSubscribersStatWidget::class),
            700
        );
        $widgetManager->registerWidget(
            'dashboard.stats.actuals.subscribers.source',
            $this->getInstance(\Crm\SubscriptionsModule\Components\ActualSubscribersRegistrationSourceStatsWidget::class),
            700
        );
        $widgetManager->registerWidget(
            'dashboard.singlestat.today',
            $this->getInstance(\Crm\SubscriptionsModule\Components\TodaySubscriptionsStatWidget::class),
            500
        );
        $widgetManager->registerWidget(
            'dashboard.singlestat.month',
            $this->getInstance(\Crm\SubscriptionsModule\Components\MonthSubscriptionsStatWidget::class),
            600
        );
        $widgetManager->registerWidget(
            'dashboard.singlestat.mtd',
            $this->getInstance(\Crm\SubscriptionsModule\Components\MonthToDateSubscriptionsStatWidget::class),
            600
        );
        $widgetManager->registerWidget(
            'dashboard.bottom',
            $this->getInstance(\Crm\SubscriptionsModule\Components\EndingSubscriptionsWidget::class),
            100
        );
        $widgetManager->registerWidget(
            'subscriptions.endinglist',
            $this->getInstance(\Crm\SubscriptionsModule\Components\SubscriptionsEndingWithinPeriodWidget::class),
            500
        );
        $widgetManager->registerWidget(
            'subscriptions.endinglist',
            $this->getInstance(\Crm\SubscriptionsModule\Components\RenewedSubscriptionsEndingWithinPeriodWidget::class),
            600
        );
        $widgetManager->registerWidget(
            'admin.users.header',
            $this->getInstance(\Crm\SubscriptionsModule\Components\MonthSubscriptionsSmallBarGraphWidget::class),
            600
        );
        $widgetManager->registerWidget(
            'admin.user.list.emailcolumn',
            $this->getInstance(\Crm\SubscriptionsModule\Components\UserSubscriptionInfoWidget::class),
            600
        );
        $widgetManager->registerWidget(
            'admin.payments.top',
            $this->getInstance(\Crm\SubscriptionsModule\Components\SubscribersWithMissingAddressWidget::class),
            2000
        );
        $widgetManager->registerWidget(
            'admin.user.abusive.additional',
            $this->getInstance(\Crm\SubscriptionsModule\Components\UsersAbusiveAdditionalWidget::class)
        );
    }

    public function registerEventHandlers(Emitter $emitter)
    {
        $emitter->addListener(
            \Crm\SubscriptionsModule\Events\NewSubscriptionEvent::class,
            $this->getInstance(\Crm\UsersModule\Events\RefreshUserDataTokenHandler::class),
            600
        );
        $emitter->addListener(
            \Crm\SubscriptionsModule\Events\SubscriptionUpdatedEvent::class,
            $this->getInstance(\Crm\UsersModule\Events\RefreshUserDataTokenHandler::class)
        );
        $emitter->addListener(
            \Crm\UsersModule\Events\AddressRemovedEvent::class,
            $this->getInstance(\Crm\SubscriptionsModule\Events\AddressRemovedHandler::class)
        );
    }

    public function registerHermesHandlers(Dispatcher $dispatcher)
    {
        $dispatcher->registerHandler(
            'generate-subscription',
            $this->getInstance(\Crm\SubscriptionsModule\Hermes\GenerateSubscriptionHandler::class)
        );
    }

    public function registerCommands(CommandsContainerInterface $commandsContainer)
    {
        $commandsContainer->registerCommand($this->getInstance(\Crm\SubscriptionsModule\Commands\ChangeSubscriptionsStateCommand::class));
    }

    public function registerApiCalls(ApiRoutersContainerInterface $apiRoutersContainer)
    {
        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('1', 'users', 'subscriptions'),
                \Crm\SubscriptionsModule\Api\v1\UsersSubscriptionsHandler::class,
                \Crm\UsersModule\Auth\UserTokenAuthorization::class
            )
        );

        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('1', 'subscriptions', 'create'),
                \Crm\SubscriptionsModule\Api\v1\CreateSubscriptionHandler::class,
                \Crm\ApiModule\Authorization\BearerTokenAuthorization::class
            )
        );

        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('1', 'content-access', 'list'),
                \Crm\SubscriptionsModule\Api\v1\ListContentAccessHandler::class,
                \Crm\ApiModule\Authorization\BearerTokenAuthorization::class
            )
        );

        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('1', 'article', 'view'),
                \Crm\CountSubscriptionModule\Api\v1\SubsctiptionUpdateOnArticelViewHandler::class,
                \Crm\UsersModule\Auth\UserTokenAuthorization::class
            )
        );
    }

    public function registerUserData(UserDataRegistrator $dataRegistrator)
    {
        $dataRegistrator->addUserDataProvider($this->getInstance(\Crm\SubscriptionsModule\User\SubscriptionsUserDataProvider::class));
    }

    public function registerScenariosCriteria(ScenariosCriteriaStorage $scenariosCriteriaStorage)
    {
        $scenariosCriteriaStorage->register('subscription', 'type', $this->getInstance(TypeCriteria::class));
        $scenariosCriteriaStorage->register('subscription', 'subscription_type', $this->getInstance(SubscriptionTypeCriteria::class));
        $scenariosCriteriaStorage->register('subscription', 'content_access', $this->getInstance(ContentAccessCriteria::class));
        $scenariosCriteriaStorage->register('subscription', 'is_recurrent', $this->getInstance(IsRecurrentCriteria::class));
        $scenariosCriteriaStorage->register('subscription', HasDisabledNotificationsCriteria::KEY, $this->getInstance(HasDisabledNotificationsCriteria::class));
        $scenariosCriteriaStorage->register('subscription', SubscriptionTypeLengthCriteria::KEY, $this->getInstance(SubscriptionTypeLengthCriteria::class));
    }

    public function registerSegmentCriteria(CriteriaStorage $criteriaStorage)
    {
        $criteriaStorage->register('users', 'users_active_subscription', $this->getInstance(\Crm\SubscriptionsModule\Segment\UserActiveSubscriptionCriteria::class));
        $criteriaStorage->register('subscriptions', 'subscriptions_active_subscription', $this->getInstance(\Crm\SubscriptionsModule\Segment\ActiveSubscriptionCriteria::class));

        $criteriaStorage->register('users', 'users_inactive_subscription', $this->getInstance(\Crm\SubscriptionsModule\Segment\InactiveSubscriptionCriteria::class));

        $criteriaStorage->setDefaultFields('subscriptions', ['id']);
        $criteriaStorage->setFields('subscriptions', [
            'start_time',
            'end_time',
            'is_recurrent',
            'type',
            'length',
            'created_at',
            'note',
        ]);
    }

    public function registerRoutes(RouteList $router)
    {
        $router[] = new Route('subscriptions/[funnel/<funnel>]', 'Subscriptions:Subscriptions:new');
    }

    public function registerSeeders(SeederManager $seederManager)
    {
        $seederManager->addSeeder($this->getInstance(ConfigSeeder::class));
        $seederManager->addSeeder($this->getInstance(ContentAccessSeeder::class));
        $seederManager->addSeeder($this->getInstance(SubscriptionExtensionMethodsSeeder::class));
        $seederManager->addSeeder($this->getInstance(SubscriptionLengthMethodSeeder::class));
        $seederManager->addSeeder($this->getInstance(SubscriptionTypeNamesSeeder::class));
    }

    public function registerAccessProvider(AccessManager $accessManager)
    {
        $accessManager->addAccessProvider($this->getInstance(\Crm\SubscriptionsModule\Access\SubscriptionAccessProvider::class));
    }

    public function registerDataProviders(DataProviderManager $dataProviderManager)
    {
        $dataProviderManager->registerDataProvider(
            'users.dataprovider.users_filter_form',
            $this->getInstance(\Crm\SubscriptionsModule\DataProvider\FilterUsersFormDataProvider::class)
        );
        $dataProviderManager->registerDataProvider(
            'users.dataprovider.filter_user_actions_log_selection',
            $this->getInstance(\Crm\SubscriptionsModule\DataProvider\FilterUserActionLogsSelectionDataProvider::class)
        );
        $dataProviderManager->registerDataProvider(
            'users.dataprovider.filter_user_actions_log_form',
            $this->getInstance(\Crm\SubscriptionsModule\DataProvider\FilterUserActionLogsFormDataProvider::class)
        );
        $dataProviderManager->registerDataProvider(
            'users.dataprovider.address.can_delete',
            $this->getInstance(CanDeleteAddressDataProvider::class)
        );
        $dataProviderManager->registerDataProvider(
            'users.dataprovider.filter_abusive_user_form',
            $this->getInstance(FilterAbusiveUserFormDataProvider::class)
        );
        $dataProviderManager->registerDataProvider(
            'users.dataprovider.claim_unclaimed_user',
            $this->getInstance(SubscriptionsClaimUserDataProvider::class)
        );
    }

    public function registerEvents(EventsStorage $eventsStorage)
    {
        $eventsStorage->register('new_subscription', Events\NewSubscriptionEvent::class, true);
        $eventsStorage->register('subscription_pre_update', Events\SubscriptionPreUpdateEvent::class);
        $eventsStorage->register('subscription_updated', Events\SubscriptionUpdatedEvent::class);
        $eventsStorage->register('subscription_starts', Events\SubscriptionStartsEvent::class);
        $eventsStorage->register('subscription_ends', Events\SubscriptionEndsEvent::class, true);
    }

    public function cache(OutputInterface $output, array $tags = [])
    {
        if (in_array('precalc', $tags, true)) {
            $output->writeln('  * Refreshing <info>subscriptions stats</info> cache');

            $this->subscriptionsRepository->totalCount(true, true);
            $this->subscriptionsRepository->currentSubscribersCount(true, true);
        }
    }
}
