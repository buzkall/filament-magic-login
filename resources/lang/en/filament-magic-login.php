<?php

return [

    'actions' => [
        'magic_link' => 'Email me a login link',
        'or' => 'or',
    ],

    'messages' => [
        'email_required' => 'Enter your email address first.',
        'sent_title' => 'Check your inbox',
        'sent_body' => 'If an account exists for that address, we\'ve sent a login link. It expires in :duration.',
        'too_many_requests_title' => 'Too many attempts',
        'too_many_requests_body' => 'Please wait :seconds seconds before trying again.',
        'invalid_title' => 'That login link can\'t be used',
        'invalid_reason' => [
            'invalid' => 'The link is not valid.',
            'expired' => 'The link has expired. Request a new one.',
            'used' => 'The link has already been used. Request a new one.',
            'cannot_access_panel' => 'You don\'t have access to this panel.',
        ],
    ],

    'mail' => [
        'subject' => 'Your login link for :app',
        'greeting' => 'Hello!',
        'intro' => 'Click the button below to sign in. The link expires in :duration and can only be used once.',
        'button' => 'Sign in',
        'ignore' => 'If you didn\'t request this, you can safely ignore this email.',
        'fallback' => 'If the button doesn\'t work, copy this URL into your browser:',
    ],

    'admin' => [
        'label' => 'Send a login link',
        'modal' => [
            'heading' => 'Send a login link',
            'description' => 'A single-use login link will be emailed to :user. It is never shown here.',
            'panel' => 'It signs them into the [:panel] panel.',
            'submit' => 'Send the link',
        ],
        'field' => [
            'expiry' => [
                'label' => 'The link expires in',
            ],
            'custom' => [
                'label' => 'Minutes',
                'helper' => 'Between 1 and :max minutes.',
            ],
        ],
        'presets' => [
            'minutes' => ':count minute|:count minutes',
            'hours' => ':count hour|:count hours',
            'days' => ':count day|:count days',
            'custom' => 'Custom',
        ],
        'sent' => [
            'title' => 'Login link sent',
            'body' => 'A login link has been emailed to :user. It expires in :duration.',
        ],
        'no_email' => [
            'title' => 'No email address',
            'body' => ':user has no email address, so there is nowhere to send the link.',
        ],
        'cannot_access' => [
            'title' => 'No access to this panel',
            'body' => ':user cannot access the [:panel] panel, so a link would not let them in.',
        ],
        'rate_limited' => [
            'title' => 'Too many links sent',
            'body' => 'Please wait :seconds seconds before sending another.',
        ],
    ],

    'exceptions' => [
        'custom_login_without_trait' => 'Panel [:panel] uses a custom login page [:class] that does not use :trait. Add the trait or call ->useCustomLoginPage().',
        'panel_without_plugin' => 'Panel [:panel] does not register the magic login plugin, so it cannot issue login links.',
        'unknown_storage_driver' => 'Unknown filament-magic-login storage driver [:driver]. Use "database" or "cache".',
        'unsafe_cache_store' => 'The [:store] cache store cannot be used for filament-magic-login in production.',
    ],

    'install' => [
        'description' => 'Install filament-magic-login',
        'intro' => 'Install filament-magic-login',
        'cache_driver_skip_migrations' => 'Storage driver is "cache": no migration needed.',
        'config_publish' => 'Publish the config file?',
        'config_skipped' => 'Skipped the config file. The package defaults apply.',
        'config_overwrite' => 'The config file is already published. Overwrite it?',
        'config_kept' => 'Kept the config file you already had.',
        'config_published' => 'Published the config file.',
        'migrations_kept' => 'The migration is already published.',
        'migrations_published' => 'Published the migration.',
        'run_migrations' => 'Run the migrations now?',
        'migrations_ran' => 'Migrations run.',
        'migrations_skipped' => 'Skipped the migrations. Run them before the first login link is sent.',
        'schedule_prompt' => 'Schedule daily pruning of expired tokens?',
        'schedule_added' => 'Added the pruner to [:path].',
        'schedule_exists' => 'The pruner is already scheduled.',
        'schedule_failed' => 'Could not edit [:path] safely, so it was left untouched.',
        'schedule_manual' => 'Schedule the pruner yourself with:',
        'plugin_registered' => 'The plugin is already registered in [:path].',
        'plugin_prompt' => 'Register the plugin in [:path]?',
        'plugin_added' => 'Added the plugin to [:path].',
        'plugin_failed' => 'Could not edit [:path] safely, so it was left untouched.',
        'plugin_manual' => 'Register the plugin on the panel you want it on:',
        'resource_prompt' => 'Add the "send a login link" action to [:path]?',
        'resource_added' => 'Added the action to [:path].',
        'resource_exists' => 'The action is already in [:path].',
        'resource_failed' => 'Could not edit [:path] safely, so it was left untouched.',
        'resource_missing' => 'Found no single Filament resource for [:model], so nothing was changed.',
        'resource_manual' => 'Add the action to your user resource yourself:',
        'done' => 'filament-magic-login has been installed.',
    ],

    'uninstall' => [
        'description' => 'Remove the files filament-magic-login published, and its stored tokens',
        'confirm' => 'This deletes the published config and migration, and drops every stored login token. Continue?',
        'aborted' => 'Nothing was removed.',
        'drop_table' => 'Drop the [:table] table and every token in it?',
        'table_dropped' => 'Dropped the [:table] table.',
        'table_missing' => 'No [:table] table to drop.',
        'table_kept' => 'Kept the [:table] table.',
        'table_kept_hint' => 'Its rows are still there, and installing the package again will find the table in place. To drop it: php artisan :command',
        'cache_note' => 'Storage driver is "cache": stored links expire on their own, there is nothing to drop.',
        'deleted' => 'Deleted [:path].',
        'missing' => 'Nothing published at [:path].',
        'panels_warning' => 'Still registered on these panels, remove the plugin from them first: :panels',
        'code_updated' => 'Removed the package from [:path].',
        'code_manual' => '[:path] still mentions the package on line(s) :lines, remove those by hand.',
        'next_steps' => 'Now run: composer remove arzcode/filament-magic-login',
        'done' => 'filament-magic-login has been uninstalled.',
    ],

];
