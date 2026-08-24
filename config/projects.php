<?php

return [
    /**
     * Shared Slack channel for project create + status updates (also posted to
     * each account's in_house_slack when set).
     */
    'slack_channel' => env('PROJECTS_SLACK_CHANNEL', '#projects'),
];
