<?php
declare(strict_types=1);
/**
 * @author Joachim Bauch <mail@joachim-bauch.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Talk\AppInfo;

use OCA\Talk\Activity\Listener as ActivityListener;
use OCA\Talk\Capabilities;
use OCA\Talk\Chat\Changelog\Listener as ChangelogListener;
use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Chat\Command\Listener as CommandListener;
use OCA\Talk\Chat\Parser\Listener as ParserListener;
use OCA\Talk\Chat\SystemMessage\Listener as SystemMessageListener;
use OCA\Talk\Collaboration\Resources\ConversationProvider;
use OCA\Talk\Collaboration\Resources\Listener as ResourceListener;
use OCA\Talk\Config;
use OCA\Talk\Events\ChatEvent;
use OCA\Talk\Events\RoomEvent;
use OCA\Talk\Files\Listener as FilesListener;
use OCA\Talk\Files\TemplateLoader as FilesTemplateLoader;
use OCA\Talk\Listener;
use OCA\Talk\Listener\RestrictStartingCalls as RestrictStartingCallsListener;
use OCA\Talk\Middleware\CanUseTalkMiddleware;
use OCA\Talk\Middleware\InjectionMiddleware;
use OCA\Talk\Notification\Listener as NotificationListener;
use OCA\Talk\Notification\Notifier;
use OCA\Talk\PublicShareAuth\Listener as PublicShareAuthListener;
use OCA\Talk\PublicShareAuth\TemplateLoader as PublicShareAuthTemplateLoader;
use OCA\Talk\PublicShare\TemplateLoader as PublicShareTemplateLoader;
use OCA\Talk\Room;
use OCA\Talk\Settings\Personal;
use OCA\Talk\Share\RoomShareProvider;
use OCA\Talk\Signaling\Listener as SignalingListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Collaboration\Resources\IManager as IResourceManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IServerContainer;
use OCP\IUser;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCP\Security\FeaturePolicy\AddFeaturePolicyEvent;
use OCP\Settings\IManager;

class Application extends App {

	public function __construct(array $urlParams = []) {
		parent::__construct('spreed', $urlParams);

		// This needs to be in the constructor,
		// because otherwise the middleware is registered on a wrong object,
		// when it is requested by the Router.
		$this->getContainer()->registerMiddleWare(CanUseTalkMiddleware::class);
		$this->getContainer()->registerMiddleWare(InjectionMiddleware::class);
	}

	public function register(): void {
		$server = $this->getContainer()->getServer();

		$this->registerNotifier($server);
		$this->registerCollaborationResourceProvider($server);
		$this->getContainer()->registerCapability(Capabilities::class);

		/** @var IEventDispatcher $dispatcher */
		$dispatcher = $server->query(IEventDispatcher::class);

		Listener::register($dispatcher);
		ActivityListener::register($dispatcher);
		NotificationListener::register($dispatcher);
		SystemMessageListener::register($dispatcher);
		ParserListener::register($dispatcher);
		PublicShareAuthListener::register($dispatcher);
		PublicShareAuthTemplateLoader::register($dispatcher);
		PublicShareTemplateLoader::register($dispatcher);
		FilesListener::register($dispatcher);
		FilesTemplateLoader::register($dispatcher);
		RestrictStartingCallsListener::register($dispatcher);
		RoomShareProvider::register($dispatcher);
		SignalingListener::register($dispatcher);
		CommandListener::register($dispatcher);
		ResourceListener::register($dispatcher);
		ChangelogListener::register($dispatcher);

		$dispatcher->addServiceListener(AddContentSecurityPolicyEvent::class, Listener\CSPListener::class);
		$dispatcher->addServiceListener(AddFeaturePolicyEvent::class, Listener\FeaturePolicyListener::class);

		$this->registerNavigationLink($server);
		$this->registerRoomActivityHooks($dispatcher);
		$this->registerChatHooks($dispatcher);
		$this->registerClientLinks($server);
	}

	protected function registerNotifier(IServerContainer $server): void {
		$manager = $server->getNotificationManager();
		$manager->registerNotifierService(Notifier::class);
	}

	protected function registerCollaborationResourceProvider(IServerContainer $server): void {
		/** @var IResourceManager $resourceManager */
		$resourceManager = $server->query(IResourceManager::class);
		$resourceManager->registerResourceProvider(ConversationProvider::class);
		\OC::$server->getEventDispatcher()->addListener('\OCP\Collaboration\Resources::loadAdditionalScripts', function () {
			\OCP\Util::addScript('spreed', 'collections');
		});
	}

	protected function registerClientLinks(IServerContainer $server): void {
		if ($server->getAppManager()->isEnabledForUser('firstrunwizard')) {
			/** @var IManager $settingManager */
			$settingManager = $server->getSettingsManager();
			$settingManager->registerSetting('personal', Personal::class);
		}
	}

	protected function registerNavigationLink(IServerContainer $server): void {
		$server->getNavigationManager()->add(function() use ($server) {
			/** @var Config $config */
			$config = $server->query(Config::class);
			$user = $server->getUserSession()->getUser();
			return [
				'id' => 'spreed',
				'name' => $server->getL10N('spreed')->t('Talk'),
				'href' => $server->getURLGenerator()->linkToRouteAbsolute('spreed.Page.index'),
				'icon' => $server->getURLGenerator()->imagePath('spreed', 'app.svg'),
				'order' => 3,
				'type' => $user instanceof IUser && !$config->isDisabledForUser($user) ? 'link' : 'hidden',
			];
		});
	}

	protected function registerRoomActivityHooks(IEventDispatcher $dispatcher): void {
		$listener = function(ChatEvent $event) {
			$room = $event->getRoom();
			/** @var ITimeFactory $timeFactory */
			$timeFactory = $this->getContainer()->query(ITimeFactory::class);
			$room->setLastActivity($timeFactory->getDateTime());
		};

		$dispatcher->addListener(ChatManager::class . '::postSendMessage', $listener);
		$dispatcher->addListener(ChatManager::class . '::postSendSystemMessage', $listener);
	}

	protected function registerChatHooks(IEventDispatcher $dispatcher): void {
		$listener = function(RoomEvent $event) {
			/** @var ChatManager $chatManager */
			$chatManager = $this->getContainer()->query(ChatManager::class);
			$chatManager->deleteMessages($event->getRoom());
		};
		$dispatcher->addListener(Room::class . '::postDeleteRoom', $listener);
	}
}
