<?php

return [
    /**
     * Legacy users.role values imported as portal users (1 = Customer Service, 2 = Customer admin).
     */
    'portal_legacy_roles' => [1, 2],

    /**
     * Fallback bcrypt password when legacy row has no usable password hash.
     */
    'default_password' => 'backup',
];
