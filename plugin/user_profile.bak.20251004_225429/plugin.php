<?php
/* For licensing terms, see /license.txt */
/**
 *
 * @author Yannick Verhenne
 *
 */
require_once __DIR__.'/config.php';
require_once __DIR__.'/UserProfilePlugin.php';

$plugin = UserProfilePlugin::create();
$plugin->ensureConfigurationSchema();

$plugin_info = $plugin->get_info();

// Build settings form for configure_plugin.php
$form = new FormValidator('user_profile_settings');
// Use plugin translations to ensure labels come from plugin lang files
$form->addText('email', $plugin->get_lang('Email'), false, ['placeholder' => 'email@example.com']);
$form->addText('id_ticket_communication', $plugin->get_lang('TicketCommunicationId'), false);
// Category 1 comes right after communication ticket ID
$form->addText('category1', $plugin->get_lang('Category1'), false);
$form->addText('id_ticket_learner_tracking', $plugin->get_lang('TicketLearnerTrackingId'), false);
// Category 2 comes right after learner tracking ticket ID
$form->addText('category2', $plugin->get_lang('Category2'), false);
$form->addButtonSave($plugin->get_lang('Save'));

// Prefill from plugin configuration table
$config = $plugin->getConfiguration();
$form->setDefaults([
    'email' => $config['email'] ?? '',
    'id_ticket_communication' => $config['id_ticket_communication'] ?? '',
    'id_ticket_learner_tracking' => $config['id_ticket_learner_tracking'] ?? '',
    'category1' => $config['category1'] ?? '',
    'category2' => $config['category2'] ?? '',
]);

$plugin_info['settings_form'] = $form;
$plugin_info['settings'] = [
    'email' => $config['email'] ?? '',
    'id_ticket_communication' => $config['id_ticket_communication'] ?? '',
    'id_ticket_learner_tracking' => $config['id_ticket_learner_tracking'] ?? '',
    'category1' => $config['category1'] ?? '',
    'category2' => $config['category2'] ?? '',
];
