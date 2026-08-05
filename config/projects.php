<?php

return [
    /**
     * Deprecated for project create/status Slack: those posts go only to each
     * account's in_house_slack. Kept for env compatibility; unused by project
     * Slack services.
     */
    'slack_channel' => env('PROJECTS_SLACK_CHANNEL', '#projects'),
];
