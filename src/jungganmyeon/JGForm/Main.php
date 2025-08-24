<?php

namespace jungganmyeon\JGForm;

use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;

class Main extends PluginBase {
    private FormHandler $formHandler;

    public function onEnable(): void {
        /**AUTO COPY FILE FROM RESOURCES TO PLUGIN_DATA*/
        foreach(array_keys($this->getResources()) as $file){
            $this->saveResource($file);
        }
        
        @mkdir($this->getDataFolder() . "form/");
        $this->formHandler = new FormHandler($this);
        $this->formHandler->loadForms();
        $this->getServer()->getCommandMap()->register($this->getName(), new ReloadCommand($this));
    }

    public function getFormHandler(): FormHandler {
        return $this->formHandler;
    }
}

