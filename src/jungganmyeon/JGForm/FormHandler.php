<?php

namespace jungganmyeon\JGForm;

use pocketmine\player\Player;
use pocketmine\permission\PermissionManager;
use pocketmine\permission\Permission;
use pocketmine\form\Form;
use pocketmine\utils\Config;
use onebone\economyapi\EconomyAPI;

class FormHandler {
    private Main $plugin;
    private array $forms = [];

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function loadForms(): void {
        $this->forms = [];
        foreach (glob($this->plugin->getDataFolder() . "form/*.yml") as $file) {
            $config = new Config($file, Config::YAML);
            $formName = $config->get("formname");
            $styleForm = $config->get("styleform");
            $command = $config->get("command");
            $per = $config->get("permission");

            if ($formName && $styleForm && $command) {
                $formData = $config->getAll();
                $this->forms[$command] = $formData;

                if (PermissionManager::getInstance()->getPermission($per) == null) {
                    PermissionManager::getInstance()->addPermission(new Permission($per, "Permission for " . $formName));
                }

                $this->plugin->getServer()->getCommandMap()->register($this->plugin->getName(), new FormCommand($this->plugin, $command, "Open the $formName", $per, $command));

                $this->plugin->getLogger()->info("Loaded form '$formName' for command '$command'.");
            } else {
                $this->plugin->getLogger()->warning("Form configuration is missing required fields in '$file'.");
            }
        }

        $this->plugin->getLogger()->info("Total forms loaded: " . count($this->forms));
    }

    public function sendForm(Player $player, string $command): void {
        if (isset($this->forms[$command])) {
            $formData = $this->forms[$command];
            $form = $this->createForm($formData);
            $player->sendForm($form);
        } else {
            $this->plugin->getLogger()->warning("Form for command '$command' does not exist.");
        }
    }

    public function getForms(): array {
        return $this->forms;
    }

    private function createForm(array $formData): Form {
        switch ($formData["styleform"]) {
            case "modal":
                return $this->createModalForm($formData);
            case "simple":
                return $this->createSimpleForm($formData);
            case "custom":
                return $this->createCustomForm($formData);
            default:
                throw new \InvalidArgumentException("Invalid form style: " . $formData["styleform"]);
        }
    }

    private function createSimpleForm(array $formData): Form {
        return new class($formData, $this->plugin) implements Form {
            private array $formData;
            private Main $plugin;

            public function __construct(array $formData, Main $plugin) {
                $this->formData = $formData;
                $this->plugin = $plugin;
            }

            public function jsonSerialize(): array {
                $buttons = [];
                foreach ($this->formData["buttons"] as $button) {
                    $buttonData = ["text" => $button["text"]];
                    if (isset($button["image"])) {
                        $buttonData["image"] = [
                            "type" => $button["image"]["type"],
                            "data" => $button["image"]["data"]
                        ];
                    }
                    $buttons[] = $buttonData;
                }

                return [
                    "type" => "form",
                    "title" => $this->formData["formname"],
                    "content" => $this->formData["content"][0],
                    "buttons" => $buttons
                ];
            }

            public function handleResponse(Player $player, $data): void {
                if (is_int($data) && isset($this->formData["buttons"][$data])) {
                    $button = $this->formData["buttons"][$data];

                    if ($this->checkRequirements($player, $button)) {
                        $this->executeCommands($player, $button["command"]);
                    } else {
                        $this->plugin->getLogger()->info("Player {$player->getName()} does not meet the requirements for button '{$button["text"]}'.");
                        $this->executeCommands($player, $button["not_enough_requirements"]["command"]);
                    }
                }
            }

            private function checkRequirements(Player $player, array $button): bool {
                if (isset($button["requirements"]["money"])) {
                    if(class_exists(EconomyAPI::class)) {
                        $moneyRequired = $button["requirements"]["money"]["output"];
                        $economyAPI = EconomyAPI::getInstance();
                        return $economyAPI->myMoney($player) >= $moneyRequired;
                    }
                    $this->plugin->getLogger()->warning("EconomyAPI not found. Requirement check failed for {$player->getName()}.");
                    return false;
                }
                return true;
            }

            private function executeCommands(Player $player, array $commands): void {
                foreach ($commands as $command) {
                    $commandType = $command[0];
                    $commandString = str_replace("{player}", $player->getName(), $command[1]);

                    switch ($commandType) {
                        case "console":
                            $this->plugin->getServer()->dispatchCommand($this->plugin->getServer()->getConsoleSender(), $commandString);
                            break;
                        case "player":
                            $this->plugin->getServer()->dispatchCommand($player, $commandString);
                            break;
                    }
                }
            }
        };
    }

    private function createModalForm(array $formData): Form {
        return new class($formData, $this->plugin) implements Form {
            private array $formData;
            private Main $plugin;

            public function __construct(array $formData, Main $plugin) {
                $this->formData = $formData;
                $this->plugin = $plugin;
            }

            public function jsonSerialize(): array {
                return [
                    "type" => "modal",
                    "title" => $this->formData["formname"],
                    "content" => $this->formData["content"][0],
                    "button1" => $this->formData["button1"]["text"],
                    "button2" => $this->formData["button2"]["text"],
                    "image" => $this->formData["image"] ?? null
                ];
            }

            public function handleResponse(Player $player, $data): void {
                $button = $data ? $this->formData["button1"] : $this->formData["button2"];
                $commandType = $button["command"][0];
                $command = str_replace("{player}", $player->getName(), $button["command"][1]);

                switch ($commandType) {
                    case "console":
                        $this->plugin->getServer()->dispatchCommand($this->plugin->getServer()->getConsoleSender(), $command);
                        break;
                    case "player":
                        $this->plugin->getServer()->dispatchCommand($player, $command);
                        break;
                }
            }
        };
    }

    private function createCustomForm(array $formData): Form {
        return new class($formData, $this->plugin) implements Form {
            private array $formData;
            private Main $plugin;

            public function __construct(array $formData, Main $plugin) {
                $this->formData = $formData;
                $this->plugin = $plugin;
            }

            public function jsonSerialize(): array {
                $content = [];
                foreach ($this->formData["content"] as $element) {
                    $elementData = ["type" => $element["type"], "text" => $element["text"]];
                    if (isset($element["placeholder"])) {
                        $elementData["placeholder"] = $element["placeholder"];
                    }
                    if (isset($element["min"])) {
                        $elementData["min"] = $element["min"];
                    }
                    if (isset($element["max"])) {
                        $elementData["max"] = $element["max"];
                    }
                    if (isset($element["image"])) {
                        $elementData["image"] = [
                            "type" => $element["image"]["type"],
                            "data" => $element["image"]["data"]
                        ];
                    }
                    $content[] = $elementData;
                }

                return [
                    "type" => "custom_form",
                    "title" => $this->formData["formname"],
                    "content" => $content
                ];
            }

            public function handleResponse(Player $player, $data): void {
                if (is_array($data)) {
                    foreach ($data as $index => $response) {
                        if (isset($this->formData["content"][$index]) && isset($this->formData["content"][$index]["command"])) {
                            $commandType = $this->formData["content"][$index]["command"][0];
                            $command = str_replace("{input}", $response, $this->formData["content"][$index]["command"][1]);

                            switch ($commandType) {
                                case "console":
                                    $this->plugin->getServer()->dispatchCommand($this->plugin->getServer()->getConsoleSender(), $command);
                                    break;
                                case "player":
                                    $this->plugin->getServer()->dispatchCommand($player, $command);
                                    break;
                            }
                        }
                    }
                }
            }
        };
    }
}
