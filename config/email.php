<?php

/*
 * Shabllonet e email-it të Emiron FX — motori kanonik i Botble-s
 * (i njëjti parim si RULE_092 i RadiusManager): editohen te
 * Settings → Email → Emiron FX.
 */
return [
    'name' => 'plugins/emiron-fx::fx.email.name',
    'description' => 'plugins/emiron-fx::fx.email.description',
    'templates' => [
        'fx-rate-alert' => [
            'title' => 'plugins/emiron-fx::fx.email.alert_title',
            'description' => 'plugins/emiron-fx::fx.email.alert_description',
            'subject' => '{{ fx_alert_title }}',
            'can_off' => true,
            'enabled' => true,
            'variables' => [
                'fx_alert_title' => 'plugins/emiron-fx::fx.email.var_title',
                'fx_alert_intro' => 'plugins/emiron-fx::fx.email.var_intro',
                'fx_alert_lines' => 'plugins/emiron-fx::fx.email.var_lines',
                'fx_action_url' => 'plugins/emiron-fx::fx.email.var_url',
            ],
        ],
    ],
    'variables' => [],
];
