<?php
/* For licensing terms, see /license.txt */

/**
 * Hook observer for the user_profile plugin.
 *
 * Listens to user create/update events to persist plugin-specific
 * values, and can inject admin blocks if required.
 */
class HookUserProfile extends HookObserver implements HookCreateUserObserverInterface, HookUpdateUserObserverInterface, HookAdminBlockObserverInterface
{
    protected function __construct()
    {
        parent::__construct('plugin/user_profile/UserProfilePlugin.php', 'user_profile');
    }

    public static function create(): self
    {
        static $instance = null;
        return $instance ?: $instance = new self();
    }

    /**
     * Handle user creation events.
     */
    public function hookCreateUser(HookCreateUserEventInterface $hook)
    {
        if (!api_get_configuration_value('plugin_user_profile_enabled')) {
            return 0;
        }
        if ($hook->getEventData()['type'] === HOOK_EVENT_TYPE_POST) {
            $userId = $hook->getEventData()['return'];
            UserProfilePlugin::create()->saveUserValues($userId, $_POST);
        }
    }

    /**
     * Handle user update events.
     */
    public function hookUpdateUser(HookUpdateUserEventInterface $hook)
    {
        if (!api_get_configuration_value('plugin_user_profile_enabled')) {
            return 0;
        }
        if ($hook->getEventData()['type'] === HOOK_EVENT_TYPE_POST) {
            $user = $hook->getEventData()['user'];
            if (is_object($user) && method_exists($user, 'getId')) {
                UserProfilePlugin::create()->saveUserValues($user->getId(), $_POST);
            }
        }
    }

    /**
     * Optionally inject admin blocks.
     */
    public function hookAdminBlock(HookAdminBlockEventInterface $hook)
    {
        // No injection via hook; handled in main/admin/index.php for clarity
        return 0;
    }

    /**
     * Optional hook for user deletion if the platform exposes it.
     *
     * @param mixed $hook Event payload (type varies between versions)
     */
    public function hookDeleteUser($hook)
    {
        if (!api_get_configuration_value('plugin_user_profile_enabled')) {
            return 0;
        }
        $data = is_object($hook) && method_exists($hook, 'getEventData') ? $hook->getEventData() : [];
        $userId = null;
        if (isset($data['id'])) { $userId = (int) $data['id']; }
        elseif (isset($data['user_id'])) { $userId = (int) $data['user_id']; }
        elseif (isset($data['user']) && is_object($data['user']) && method_exists($data['user'], 'getId')) { $userId = (int) $data['user']->getId(); }
        if ($userId) {
            UserProfilePlugin::create()->deleteUserData($userId);
        }
        return 1;
    }
}
