<?php

return [
    /**
     * Shared projects Slack channel. Project notifications also post to each
     * account's in_house_slack channel when set.
     */
    'slack_channel' => env('PROJECTS_SLACK_CHANNEL', '#projects'),
];
