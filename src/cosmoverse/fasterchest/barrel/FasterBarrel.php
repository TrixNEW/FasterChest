<?php
declare(strict_types=1);

namespace cosmoverse\fasterchest\barrel;

use cosmoverse\fasterchest\FasterChestSerializer;
use LevelDB;
use Logger;
use pocketmine\block\inventory\BarrelInventory;
use pocketmine\block\tile\Barrel;
use pocketmine\block\tile\Tile;
use pocketmine\block\tile\TileFactory;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\Position;
use pocketmine\world\World;
use function implode;

class FasterBarrel extends Barrel{

    public const TAG_INITIALIZED = "fasterbarrel:initialized";
    public const STATE_NOT_LOADED = 0;
    public const STATE_LOADING = 1;
    public const STATE_UNSAVED_CHANGES = 2;
    public const STATE_ALL_SAVED_CHANGES = 3;

    public static FasterChestSerializer $serializer;
    public static LevelDB $database;
    public static ?Logger $logger = null;

    public static function dbIdFromPosition(Position $position) : string{
        return implode("|", [
            "barrel",
            (string) (int) $position->x,
            (string) (int) $position->y,
            (string) (int) $position->z,
            $position->world->getFolderName()
        ]);
    }

    private string $db_identifier;
    public bool $initialized = false;

    /** @var self::STATE_* */
    private int $state = self::STATE_NOT_LOADED;

    public function __construct(World $world, Vector3 $pos){
        $this->db_identifier = self::dbIdFromPosition(Position::fromObject($pos, $world));
        parent::__construct($world, $pos);
        $this->inventory->getListeners()->add(FasterBarrelInventoryListener::instance());
    }

    public function readSaveData(CompoundTag $nbt) : void{
        $this->initialized = (bool) $nbt->getByte(self::TAG_INITIALIZED, 0);
        parent::readSaveData($nbt);
    }

    protected function writeSaveData(CompoundTag $nbt) : void{
        $nbt->setByte(self::TAG_INITIALIZED, (int) $this->initialized);
        parent::writeSaveData($nbt);
    }

    protected function loadItems(CompoundTag $tag) : void{
    }

    protected function saveItems(CompoundTag $tag) : void{
        if($this->state !== self::STATE_UNSAVED_CHANGES){
            self::$logger?->info("No need to save barrel at {$this->position}");
            return;
        }

        $buffer = self::$serializer->serialize($this->inventory->getContents());
        self::$database->put($this->db_identifier, $buffer);
        $this->state = self::STATE_ALL_SAVED_CHANGES;
        self::$logger?->info("Saved barrel at {$this->position}");
    }

    public function getInventory() : BarrelInventory{
        $this->loadFromDb();
        return parent::getInventory();
    }

    public function getDbIdentifier() : string{
        return $this->db_identifier;
    }

    /**
     * @return self::STATE_*
     */
    public function getState() : int{
        return $this->state;
    }

    public function setUnsavedChanges() : void{
        $this->state = self::STATE_UNSAVED_CHANGES;
    }

    public function loadFromDb(bool $force = false) : void{
        if($this->state !== self::STATE_NOT_LOADED && !$force){
            return;
        }
        $this->state = self::STATE_LOADING;

        if(!$this->initialized){
            $this->initialized = true;
            self::$database->delete($this->db_identifier);
            self::$logger?->info("Initialized barrel at {$this->position}");
        }else{
            $buffer = self::$database->get($this->db_identifier);
            $contents = $buffer !== false ? self::$serializer->deserialize($buffer) : [];
            $this->inventory->getListeners()->remove(FasterBarrelInventoryListener::instance());
            $this->inventory->setContents($contents);
            $this->inventory->getListeners()->add(FasterBarrelInventoryListener::instance());
            self::$logger?->info("Loaded barrel from database at {$this->position}");
        }
        $this->state = self::STATE_ALL_SAVED_CHANGES;
    }

    public function addAdditionalSpawnData(CompoundTag $nbt) : void{
        parent::addAdditionalSpawnData($nbt);
        $nbt->setString(Tile::TAG_ID, TileFactory::getInstance()->getSaveId(Barrel::class));
    }
}