# Github Plugin Updater
 Helper library to implement wordpress plugin update from github

## Usage
Add following code at the bottom of your plugin's main file.
```php
if ( class_exists( '\Shazzad\GithubPlugin\Updater' ) ) {
     new \Shazzad\GithubPlugin\Updater( array(
         'file'         => __FILE__,
         'owner'        => 'shazzad', // Name of the repo owner/organization
         'repo'         => 'w4-loggable', // Repository name
	
         // Folloing only required for private repo
         'private_repo' => false, // Is private repo
         'owner_name'   => 'Shazzad' // Owner name is used on api key settings
    ) );
}```
