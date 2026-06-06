{
    "name": "axelraboit/aurora-{{MODULE_KEBAB}}",
    "description": "{{MODULE_LABEL}} module for the Aurora platform.",
    "type": "symfony-bundle",
    "license": "proprietary",
    "require": {
        "php": ">=8.4",
        "axelraboit/aurora": "@dev"
    },
    "autoload": {
        "psr-4": {
            "Aurora\\Module\\{{MODULE}}\\": ""
        }
    },
    "extra": {
        "symfony": {
            "bundle": "Aurora\\Module\\{{MODULE}}\\Aurora{{MODULE}}Bundle"
        }
    }
}
