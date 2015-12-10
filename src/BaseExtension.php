<?php
namespace Bolt;

use Bolt\Asset\Snippet\Snippet;
use Bolt\Asset\Widget\Widget;
use Bolt\Extension\SimpleExtension;
use Bolt\Extensions\AssetTrait;
use Bolt\Extensions\ExtensionInterface;
use Bolt\Extensions\TwigProxy;
use Bolt\Helpers\Arr;
use Bolt\Library as Lib;
use Bolt\Response\BoltResponse;
use Composer\Json\JsonFile;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml;

/**
 * @deprecated Deprecated since 3.0, to be removed in 4.0.
 */
abstract class BaseExtension extends SimpleExtension
{
    use AssetTrait;

    public $config;

    protected $app;
    protected $basepath;
    protected $namespace;
    protected $functionlist;
    protected $filterlist;
    protected $snippetlist;
    /** @var TwigProxy */
    protected $twigExtension;
    protected $installtype = 'composer';

    private $extensionConfig;
    private $composerJsonLoaded;
    private $composerJson;
    private $configLoaded;

    /**
     * @deprecated Deprecated since 3.0, to be removed in 4.0.
     */
    abstract protected function initialize();

    /**
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;

        $this->setBasepath();

        // Don't load config just yet. Let 'Extensions' handle this when
        // activating, just clear the "configLoaded" flag to tell the
        // lazy-loading mechanism to do its thing.
        $this->configLoaded = false;
        $this->extensionConfig = null;
        $this->composerJsonLoaded = false;

        $this->functionlist = [];
        $this->filterlist = [];
        $this->snippetlist = [];
    }

    /**
     * {@inheritdoc}
     */
    protected function getApp()
    {
        return $this->app;
    }

    /**
     * Set the 'basepath' and the 'namespace' for the extension. We can't use
     * __DIR__, because that would give us the base path for BaseExtension.php
     * (the file you're looking at), rather than the base path for the actual,
     * derived, extension class.
     *
     * @see http://stackoverflow.com/questions/11117637/getting-current-working-directory-of-an-extended-class-in-php
     */
    private function setBasepath()
    {
        $reflection = new \ReflectionClass($this);
        $basepath = dirname($reflection->getFileName());
        $this->basepath = $this->app['pathmanager']->create($basepath);
        $this->namespace = basename(dirname($reflection->getFileName()));
    }

    /**
     * Get the base path, that is, the directory where the (derived) extension
     * class file is located. The base path is the "root directory" under which
     * all files related to the extension can be found.
     *
     * @return string
     */
    public function getBasePath()
    {
        return $this->basepath;
    }

    /**
     * Get the extensions base URL.
     *
     * @return string
     */
    public function getBaseUrl()
    {
        $relative = str_replace($this->app['resources']->getPath('extensions'), '', $this->basepath);

        return $this->app['resources']->getUrl('extensions') . ltrim($relative, '/') . '/';
    }

    /**
     * Set the extension install type.
     *
     * @param string $type
     */
    public function setInstallType($type)
    {
        if ($type === 'composer' || $type === 'local') {
            $this->installtype = $type;
        }
    }

    /**
     * Get the extension type.
     *
     * @return string
     */
    public function getInstallType()
    {
        return $this->installtype;
    }

    /**
     * Gets the Composer name, e.g. 'bolt/foobar-extension'.
     *
     * @return string|null The Composer name for this extension, or NULL if the
     *                     extension is not composerized.
     */
    public function getComposerName()
    {
        $composerjson = $this->getComposerJSON();
        if (isset($composerjson['name'])) {
            return $composerjson['name'];
        } else {
            return null;
        }
    }

    /**
     * Gets a 'machine name' for this extension.
     * The machine name is the composer name, if available, or a slugified
     * version of the name as reported by getName() otherwise.
     *
     * @return string
     */
    public function getMachineName()
    {
        $composerName = $this->getComposerName();
        if (empty($composerName)) {
            return $this->app['slugify']->slugify($this->getName());
        } else {
            return $composerName;
        }
    }

    /**
     * Get the contents of the extension's composer.json file, lazy-loading
     * as needed.
     */
    public function getComposerJSON()
    {
        if (!$this->composerJsonLoaded && !$this->composerJson) {
            $this->composerJsonLoaded = true;
            $this->composerJson = null;
            $jsonFile = new JsonFile($this->getBasepath() . '/composer.json');
            if ($jsonFile->exists()) {
                $this->composerJson = $jsonFile->read();
            }
        }

        return $this->composerJson;
    }

    /**
     * This allows write access to the composer config, allowing simulation of this feature
     * even if the extension doesn't have a physical composer.json file.
     *
     * @param array $configuration
     *
     * @return array
     */
    public function setComposerConfiguration(array $configuration)
    {
        $this->composerJsonLoaded = true;
        $this->composerJson = null;
        $this->composerJson = $configuration;

        return $this->composerJson;
    }

    /**
     * Builds an array suitable for conversion to JSON, which in turn will end
     * up in a consolidated JSON file containing the configurations of all
     * installed extensions.
     */
    public function getExtensionConfig()
    {
        if (!is_array($this->extensionConfig)) {
            $composerjson = $this->getComposerJSON();
            if (is_array($composerjson)) {
                $this->extensionConfig = [
                    strtolower($composerjson['name']) => [
                        'name' => $this->getName(),
                        'json' => $composerjson,
                    ],
                ];
            } else {
                $this->extensionConfig = [
                    $this->getName() => [
                        'name' => $this->getName(),
                        'json' => [],
                    ],
                ];
            }
        }

        return $this->extensionConfig;
    }

    /**
     * Override this to provide a default configuration, which will be used
     * in the absence of a config.yml file.
     *
     * @return array
     */
    protected function getDefaultConfig()
    {
        return [];
    }

    /**
     * Load the configuration files, creating missing files as needed based on
     * the .dist default.
     *
     * @return array
     */
    public function getConfig()
    {
        if ($this->configLoaded) {
            return $this->config;
        }

        $this->config = $this->getDefaultConfig();

        // Config file name should follow the format of {ext_name}.{vendor}.yml
        // and be in the root of the extension config directory
        $basefile = explode('/', $this->getMachineName());
        $basefile = isset($basefile[1]) ? $basefile[1] . '.' . $basefile[0] : $basefile[0];
        $basefile = $this->app['resources']->getPath('extensionsconfig') . '/' . $basefile;

        // Load main config
        if ($this->isConfigValid($basefile . '.yml', true)) {
            $this->loadConfigFile($basefile . '.yml');
        }

        // Load local config
        if ($this->isConfigValid($basefile . '_local.yml', false)) {
            $this->loadConfigFile($basefile . '_local.yml');
        }

        $this->configLoaded = true;

        return $this->config;
    }

    /**
     * Test if a given config file is valid (exists and is readable) and create
     * if required.
     *
     * @param string  $configfile Fully qualified file path
     * @param boolean $create     True - create file is non-existant
     *                            False - Only test for file existance
     *
     * @return boolean
     */
    private function isConfigValid($configfile, $create)
    {
        if (file_exists($configfile)) {
            if (is_readable($configfile)) {
                return true;
            }

            // Config file exists but is not readable
            $configdir = dirname($configfile);
            $message = "Couldn't read $configfile. Please correct file " .
                       "permissions and ensure the $configdir directory readable.";
            $this->app['logger.system']->critical($message, ['event' => 'extensions']);
            $this->app['logger.flash']->error($message);

            return false;
        }

        if (!$create) {
            return false;
        }

        $fs = new Filesystem();
        $configdistfile = $this->basepath . '/config.yml.dist';

        // There are cases where the config directory may not exist yet, try to create it.
        try {
            $fs->mkdir(dirname($configfile));
        } catch (IOException $e) {
            $message = 'Unable to create extension configuration directory at ' . dirname($configfile);
            $this->app['logger.flash']->error($message);
            $this->app['logger.system']->error($message, ['event' => 'exception', 'exception' => $e]);
        }

        // If config.yml.dist exists, attempt to copy it to config.yml.
        if (is_readable($configdistfile) && is_dir(dirname($configfile))) {
            if (copy($configdistfile, $configfile)) {
                // Success!
                $this->app['logger.system']->info("Copied $configdistfile to $configfile", ['event' => 'extensions']);

                return true;
            } else {
                // Failure!!
                $configdir = dirname($configfile);
                $message = "Couldn't copy $configdistfile to $configfile: " .
                'File is not writable. Create the file manually, ' .
                "or make the $configdir directory writable.";
                $this->app['logger.system']->critical($message, ['event' => 'extensions']);
                $this->app['logger.flash']->error($message);

                return false;
            }
        }

        return false;
    }

    /**
     * Load and process a give config file.
     *
     * @param string $configfile Fully qualified file path
     */
    private function loadConfigFile($configfile)
    {
        $yamlparser = new Yaml\Parser();

        $newConfig = $yamlparser->parse(file_get_contents($configfile) . "\n");

        // Don't error on empty config files
        if (is_array($newConfig)) {
            $this->config = Arr::mergeRecursiveDistinct($this->config, $newConfig);
        }
    }

    /**
     * Allow use of the extension's Twig function in content records when the
     * content type has the setting 'allowtwig: true' is set.
     *
     * @return boolean
     */
    public function isSafe()
    {
        return false;
    }

    protected function initializeTwig()
    {
        if (!$this->twigExtension) {
            $this->twigExtension = new TwigProxy($this->getName());
        }
    }

    public function getTwigExtensions()
    {
        if ($this->twigExtension) {
            return [$this->twigExtension];
        }

        return [];
    }

    /**
     * Return the available Snippets, used in \Bolt\Extensions.
     *
     * @deprecated Deprecated since 3.0, to be removed in 4.0. Use $app['asset.queue.snippet']->getQueue()
     *
     * @return array
     */
    public function getSnippets()
    {
        $snippets = [];
        foreach ($this->app['asset.queue.snippet']->getQueue() as $snippet) {
            $snippets[] = (string) $snippet;
        }

        return $snippets;
    }

    /**
     * Insert a snippet into the generated HTML.
     *
     * @param string $location
     * @param string $callback
     * @param array  $callbackArguments
     */
    public function addSnippet($location, $callback, $callbackArguments = [])
    {
        if ($callback instanceof BoltResponse) {
            $callback = (string) $callback;
        }

        // If we pass a callback as a simple string, we need to turn it into an array.
        if (is_string($callback) && method_exists($this, $callback)) {
            $callback = [$this, $callback];
        }

        $snippet = (new Snippet())
            ->setLocation($location)
            ->setCallback($callback)
            ->setExtension($this->getName())
            ->setCallbackArguments((array) $callbackArguments)
        ;

        $this->getApp()['asset.queue.snippet']->add($snippet);
    }

    /**
     * Make sure jQuery is added.
     */
    public function addJquery()
    {
        $this->app['extensions']->addJquery();
    }

    /**
     * Don't make sure jQuery is added. Note that this does not mean that jQuery will _not_ be added.
     * It only means that the extension will not add it, but others still might do so.
     */
    public function disableJquery()
    {
        $this->app['extensions']->disableJquery();
    }

    /**
     * Returns a list of all css and js assets that are added via extensions.
     *
     * @return array
     */
    public function getAssets()
    {
        return $this->app['extensions']->getAssets();
    }

    /**
     * Clear all previously added assets.
     *
     * @deprecated Deprecated since 3.0, to be removed in 4.0.
     */
    public function clearAssets()
    {
        return $this->app['asset.queue.file']->clear();
    }

    /**
     * Add a menu option to the 'settings' menu. Note that the item is only added if the current user
     * meets the required permission.
     *
     * @see \Bolt\Extensions\addMenuOption()
     *
     * @param string $label
     * @param string $path
     * @param string $icon
     * @param string $requiredPermission (NULL if no permission is required)
     */
    public function addMenuOption($label, $path, $icon = null, $requiredPermission = null)
    {
        $this->app['extensions']->addMenuOption($label, $path, $icon, $requiredPermission);
    }

    /**
     * Check if there are additional menu-options set for the current user.
     *
     * @see \Bolt\Extensions\hasMenuOptions()
     */
    public function hasMenuOptions()
    {
        return $this->app['extensions']->hasMenuOption();
    }

    /**
     * Get an array with the additional menu-options that are set for the current user.
     *
     * @see \Bolt\Extensions\hasMenuOptions()
     */
    public function getMenuOptions()
    {
        return $this->app['extensions']->getMenuOption();
    }

    /**
     * Parse a snippet, an pass on the generated HTML to the caller (Extensions).
     *
     * @deprecated Deprecated since 3.0, to be removed in 4.0.
     *
     * @param string $callback
     * @param string $var1
     * @param string $var2
     * @param string $var3
     *
     * @return bool|string
     */
    public function parseSnippet($callback, $var1 = '', $var2 = '', $var3 = '')
    {
        if (method_exists($this, $callback)) {
            return call_user_func([$this, $callback], $var1, $var2, $var3);
        } else {
            return false;
        }
    }

    /**
     * Add a Widget to the render queue.
     *
     * @param Widget $widget
     */
    public function addWidget($widget)
    {
        if ($widget instanceof Widget) {
            return $this->app['asset.queue.widget']->add($widget);
        }
        $this->app['logger.system']->error(sprintf('%s tried inserting an invalid widget object. Ignoring.', $this->getName()), ['event' => 'extensions']);
    }

    /**
     * @deprecated Deprecated since 3.0, to be removed in 4.0.
     * @see requireUserRole()
     *
     * @param string $permission
     *
     * @return bool
     */
    public function requireUserLevel($permission = 'dashboard')
    {
        return $this->requireUserPermission($permission);
    }

    /**
     * Check if a user is logged in, and has the proper required permission. If
     * not, we redirect the user to the dashboard.
     *
     * @param string $permission
     *
     * @return bool True if permission allowed
     */
    public function requireUserPermission($permission = 'dashboard')
    {
        if ($this->app['users']->isAllowed($permission)) {
            return true;
        } else {
            Lib::simpleredirect($this->app['config']->get('general/branding/path'));

            return false;
        }
    }

    /**
     * @deprecated Deprecated since 3.0, to be removed in 4.0.
     */
    public function parseWidget()
    {
    }

    /**
     * Add a console command.
     *
     * @param Command $command
     */
    public function addConsoleCommand(Command $command)
    {
        $this->app['nut.commands.add']($command);
    }
}
