<?php
declare(strict_types=1);

namespace cosmoverse\fasterchest\barrel;

use pocketmine\block\inventory\BarrelInventory;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\InventoryListener;
use pocketmine\item\Item;
use function assert;

final class FasterBarrelInventoryListener implements InventoryListener{

    public static function instance() : self{
        static $instance = null;
        return $instance ??= new self();
    }

    private function __construct(){
    }

    public function onSlotChange(Inventory $inventory, int $slot, Item $oldItem) : void{
        $this->onAnyChange($inventory);
    }

    public function onContentChange(Inventory $inventory, array $oldContents) : void{
        $this->onAnyChange($inventory);
    }

    private function onAnyChange(Inventory $inventory) : void{
        assert($inventory instanceof BarrelInventory);
        $position = $inventory->getHolder();
        $tile = $position->world->getTileAt($position->x, $position->y, $position->z);
        if($tile instanceof FasterBarrel){
            $tile->setUnsavedChanges();
        }
    }
}