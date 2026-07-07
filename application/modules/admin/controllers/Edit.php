<?php

use MX\MX_Controller;

class Edit extends MX_Controller
{
    private $module;
    private $manifest;
    private $configs;

    public function __construct()
    {
        // Make sure to load the administrator library!
        $this->load->library('administrator');

        parent::__construct();

        requirePermission("editModuleConfigs");

        require_once('application/libraries/ConfigEditor.php');
    }

    /**
     * Output the configs
     *
     * @param bool|String $module
     */
    public function index(bool|string $module = false): void
    {
        // Make sure the module exists and has configs
        if (
            !$module
            || !file_exists("application/modules/" . $module . "/")
            || !$this->administrator->hasConfigs($module)
        ) {
            die();
        }

        $this->module = $module;

        $this->loadModule();
        $this->loadConfigs();

        // Change the title
        $this->administrator->setTitle($this->manifest['name']);

        $data = [
            "configs" => $this->configs,
            "moduleName" => $module,
            "url" => $this->template->page_url
        ];

        // Load my view
        $output = $this->template->loadPage("config.tpl", $data);

        // Put my view in the main box with a headline
        $content = $this->administrator->box('<a href="' . $this->template->page_url . 'admin/modules">Modules</a> &rarr; Edit &rarr; ' . $this->manifest['name'], $output);

        // Output my content. The method accepts the same arguments as template->view
        $this->administrator->view($content, false, "modules/admin/js/settings.js");
    }

    /**
     * Load the module manifest
     */
    private function loadModule()
    {
        $this->manifest = @file_get_contents("application/modules/" . $this->module . "/manifest.json");

        if (!$this->manifest) {
            die("The module <b>" . $this->module . "</b> is missing manifest.json");
        } else {
            $this->manifest = json_decode($this->manifest, true);

            // Add the module folder name as name if none was specified
            if (!array_key_exists("name", $this->manifest)) {
                $this->manifest['name'] = ucfirst($this->module);
            }
        }
    }

    /**
     * Load the module configs
     */
    private function loadConfigs()
    {
        $configPath = "application/modules/{$this->module}/config/";

        foreach (glob("{$configPath}*") as $file) {
            if (in_array(basename($file), ['routes.php', 'Event.php'])) {
                continue;
            }

            $this->getConfig($file);
        }
    }


    /**
     * Load the config into the function variable scope and assign it to the configs array
     */
    private function getConfig($file)
    {
        $comments = [];

        $fileContent = file($file);

        foreach ($fileContent as $line) {
            if (preg_match('/^\s*\$config\s*\[\s*[\'"](.+?)[\'"]\s*\]\s*=.*?;\s*\/\/\s*(.+)$/', $line, $matches)) {
                $comments[$matches[1]] = trim($matches[2]);
            }
        }

        include($file);

        // Skip! don't list this file
        if (isset($config) && isset($config['force_hidden']) && $config['force_hidden'])
            return;

        $name = $this->getConfigName($file);
        $this->configs[$name] = $config;
        $this->configs[$name]['source'] = $this->getConfigSource($file);

        // load comments
        foreach ($comments as $key => $comment) {
            $this->configs[$name]['__comments'][$key] = $comment;
        }
    }

    private function getConfigSource($file)
    {
        $handle = fopen($file, "r");
        $data = fread($handle, filesize($file));
        fclose($handle);

        return $data;
    }

    /**
     * Get the config name out of the path
     *
     * @param String $path
     * @return String
     */
    private function getConfigName(string $path = ""): string
    {
        return preg_replace("/application\/modules\/" . $this->module . "\/config\/([A-Za-z0-9_-]*)\.php/", "$1", $path);
    }

    public function save($module = false, $name = false)
    {
        $module = $this->sanitizeModule($module);
        $name = $this->sanitizeConfigName($name);

        if (!$name || !$module || !$this->configExists($module, $name)) {
            die("Invalid module or config name");
        } else {
            if ($this->input->post()) {
                $fusionConfig = new ConfigEditor($this->getConfigPath($module, $name));

                foreach ($this->input->post() as $key => $value) {
                    $fusionConfig->set($key, $value);
                }

                $fusionConfig->save();

                die("yes");
            } else {
                die("No data to set");
            }
        }
    }

    public function saveSource($module = false, $name = false)
    {
        $module = $this->sanitizeModule($module);
        $name = $this->sanitizeConfigName($name);

        if (!$name || !$module || !$this->configExists($module, $name)) {
            die("Invalid module or config name");
        } else {
            if ($this->input->post("source")) {
                try {
                    $source = ConfigEditor::patchSource($this->input->post("source"));

                    // Only write source after strict validation and value patching.
                    file_put_contents($this->getConfigPath($module, $name), $source, LOCK_EX);
                } catch (InvalidArgumentException $e) {
                    die($e->getMessage());
                }

                die("yes");
            } else {
                die("No data to set");
            }
        }
    }

    private function configExists(string $module, string $file): bool
    {
        if (file_exists($this->getConfigPath($module, $file))) {
            return true;
        } else {
            return false;
        }
    }

    private function getConfigPath(string $module, string $file): string
    {
        return 'application/modules/' . $module . '/config/' . $file . '.php';
    }

    private function sanitizeModule(string $module): false|string
    {
        $module = basename($module);

        if ($module === '' || !is_dir('application/modules/' . $module)) {
            return false;
        }

        return $module;
    }

    private function sanitizeConfigName(string $name): false|string
    {
        $name = basename($name);

        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            return false;
        }

        return $name;
    }
}
