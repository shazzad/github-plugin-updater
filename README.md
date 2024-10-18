# Github Plugin Updater

Helper library to implement wordpress plugin updates from github repository.

**This implmenetation support both public and private repository.**

## Usage

### Step 1:

Add a header (1,2,3) block named `Requirements` to your repository `README.md` file.

```md
### Requirements

- WordPress: 6.0.1
- PHP: 7.4
- Tested: 6.0.2
```

### Step 2:

Create a `CHANGELOG.md` file in you repo. Add change history with each version number.

```md
#### 1.0.2 2021-04-03

- changed admin sliders default orderby to name.
- removed unused script file.

#### 1.0.1 2021-04-02

- updated code formatting.
- update stlyes.
```

### Step 3:

Add this repository in your composer.json file.

```json
"require": {
    "shazzad/github-plugin-updater": "dev-main"
}
```

### Step 4:

Install dependecy using `composer install` or `composer update` command.

### Step 5:

Add following code at the bottom of your plugin's main file.

```php
if ( class_exists( '\Shazzad\GithubPlugin\Updater' ) ) {
     new \Shazzad\GithubPlugin\Updater(
        array(
            'file'         => __FILE__,
            // Name of the repo owner/organization
            'owner'        => 'shazzad',
            // Repository name
            'repo'         => 'wp-logs',
            // Set true if private repo
            'private_repo' => true,
            // Owner name is used on api key settings
            'owner_name'   => 'Shazzad'
        )
    );
}
```
