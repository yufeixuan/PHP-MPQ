<?php
/*
    Created by TriggerHappy
*/

require_once __DIR__ . '/mpq.debugger.php';
require_once __DIR__ . '/mpq.reader.php';
require_once __DIR__ . '/mpq.crypto.php';
require_once __DIR__ . '/mpq.gamedata.php';
require_once __DIR__ . '/mpq.constants.php';

class MPQArchive 
{
    const TYPE_DEFAULT      = 0;
    const TYPE_WC3MAP       = 1;
    const TYPE_SC2MAP       = 2;
    const TYPE_WC3CAMPAIGN  = 3;

    private $filename, $filesize;
    private $fp;
    private $type = self::TYPE_DEFAULT;

    private $initialized = false;
    private $map = null;
    private $typeParsed = false;

    private $fileData;
    private $formatVersion;
    private $archiveSize, $headerSize;

    protected $hashtable, $blocktable = NULL;
    protected $hashtableSize, $blocktableSize = 0;
    protected $hashtableOffset, $blocktableOffset = 0;
    protected $headerOffset = 0;
    protected $htFile;

    private $sectorSize = 0;
    private $stream;

    protected $debugger;
    protected $debug;

    public static $debugShowTables = false;

    function __construct($filename, $debug=false) 
    {
        $this->debug = $debug;
        $this->debugger = new MPQDebugger($this);

        if (!file_exists($filename)) 
            throw new MPQException($this, "$filename doesn't exist.");

        $this->filename = $filename;

        // Initialize the cryptography table.
        // This runs only once per session.
        if (!MPQCrypto::$table)
            MPQCrypto::initCryptTable();

        // Read the archive in binary and store the contents.
        $this->file = fopen($filename, 'rb');
        $this->filesize = filesize($filename);
        $this->stream = new MPQReader($this->file);

        // The filesize must be at least the minimum header size.
        if ($this->filesize < MPQ_HEADER_SIZE_V1)
            throw new MPQException($this, "$filename is too small.");

        $this->parseHeader();
    }

    function __destruct() 
    {
        $this->close();
    }

    function close() 
    {
        if (isset($this->file) && $this->file != null && get_resource_type($this->file) == 'file')
        {
            fclose($this->file);
        }
    }

    public function isInitialized() { return $this->initialized === true; }
    public function getFilename() { return $this->filename; }
    public function getFilesize($filename) { $r=self::getFileInfo($filename); return $r['filesize']; }
    public function getHashTable() { return $this->hashtable; }
    public function getBlockTable() { return $this->blocktable; }
    public function getGameData(){ return $this->map; }
    public function hasFile($filename) { $r=self::getFileInfo($filename); return $r['filesize'] > 0; }

    public function parseHeader() 
    {
        $header_parsed = false;
        $end_of_search = $this->filesize;
        $isWar3        = false;

        $this->stream->setPosition(0);

        // Limit the header search size to 130MB
        if ($end_of_search > 0x08000000)
            $end_of_search = 0x08000000;

        // Check if the file is a Warcraft III map.
        $buffer = $this->stream->readBytes(4);

        if ($buffer == 'HM3W')
        {
            $this->type = self::TYPE_WC3MAP;
            $this->stream->setPosition(8);

            // Store some information about the map.
            $this->map = new WC3Map($this);
            $this->map->name      = $this->stream->readString();
            $this->map->flags     = $this->stream->readUInt32();
            $this->map->playerRec = $this->stream->readUInt32();

            $isWar3 = true;

            $this->stream->setPosition(4);
        }
        else
        {
            $this->stream->setPosition(0);
        }

        // Find and parse the MPQ header.
        while (!$header_parsed && $this->stream->fp < $end_of_search)
        {
            $buffer = $this->stream->readBytes(3);

            if ($buffer == "MPQ")
            {
                $buffer[3] = $this->stream->readByte();

                if (!$isWar3 && ord($buffer[3]) == 0x1B) // user data block (1Bh)
                {
                    $udata_start = $this->stream->fp-4;

                    $this->debugger->write(sprintf("Found user data block at %08X", $udata_start));

                    $udata_max_size = $this->stream->readUInt32();
                    $header_offset  = $this->stream->readUInt32();
                    $udata_size     = $this->stream->readUInt32();

                    $this->map = new SC2Map($this);
                    $data = SC2Map::parseSerializedData($this->file, $this->stream->fp);

                    if ($data != false && $this->map->getVersionString() != null)
                        $this->map->storeSerializedData($data);
                    else
                        $this->map = null;

                    $this->stream->setPosition($udata_start+4);

                }
                elseif (ord($buffer[3]) == 0x1A) // header (1Ah)
                {
                    $this->headerOffset = $this->stream->fp - 4;
                
                    $this->debugger->write(sprintf("Found header at %08X", $this->headerOffset));

                    $this->headerSize       = $this->stream->readUInt32();
                    $this->archiveSize      = $this->stream->readUInt32();
                    $this->formatVersion    = $this->stream->readUInt16();

                    $this->sectorSize       = 512 * (1 << $this->stream->readUInt16());
                    
                    $this->hashTableOffset  = $this->stream->readUInt32() + $this->headerOffset;
                    $this->blockTableOffset = $this->stream->readUInt32() + $this->headerOffset; 
                    $this->hashTableSize    = ($this->stream->readUInt32() & BLOCK_INDEX_MASK);
                    $this->blockTableSize   = ($this->stream->readUInt32() & BLOCK_INDEX_MASK);

                    $this->hashTableOffset  = ($this->hashTableOffset & BLOCK_INDEX_MASK);
                    $this->blockTableOffset = ($this->blockTableOffset & BLOCK_INDEX_MASK);

                    $valid_header = ($this->hashTableOffset <= $this->filesize) && ($this->blockTableOffset <= $this->filesize);
                    $valid_header = ($valid_header) && ($this->hashTableOffset > 0) && ($this->blockTableOffset > 0);

                    if ($valid_header && $this->headerSize >= MPQ_HEADER_SIZE_V1)
                        $header_parsed = true;

                    $this->stream->setPosition($this->headerOffset + 4);

                }
            }
            else
            {
                // skip a byte
                $this->stream->readByte();
            }
        }

        if (!$header_parsed)
            throw new MPQException($this, "Unable to read the archive header.");

        // Limit the hashtable size to prevent memory overflow.
        $this->hashTableSize = ($this->hashTableSize & BLOCK_INDEX_MASK);

        // Read the hashtable.
        $fp = $this->hashTableOffset;
        $this->hashtable = $this->readHashtable($fp, $this->hashTableSize * 4);

        $this->debugger->write(sprintf("Hash table offset: %08X, Block table offset: %08X", $this->hashTableOffset, $this->blockTableOffset));

        // The archive is ready.
        $this->initialized = true;
        
        return true;
    }

    public function getType()
    {
        if ($this->typeParse)
            return $this->type;

        $this->typeParsed = true;

        // Check to see if the archive is a Starcraft II map.
        if ($this->type == self::TYPE_DEFAULT)
        {
            if (!isset($this->map))
                $this->map = new SC2Map($this);

            if ($this->map->parseData())
                $this->type = self::TYPE_SC2MAP;
            else
                $this->map = null;
        }
        elseif ($this->type == self::TYPE_WC3MAP)
        {
            if (!$this->hasFile("war3map.w3i") && $this->hasFile("war3campaign.w3f"))
            {
                $this->type = self::TYPE_WC3CAMPAIGN;

                $this->map = new WC3Campaign($this->map);
            }
        }

        return $this->type;
    }

    public function readHashtable(&$fp, $hash_size)
    {
        $data = array();

        $this->stream->setPosition($fp);

        for ($i = 0; $i < $hash_size; $i++)
            $data[] = $this->stream->readUInt32();

        $fp = $this->stream->fp;

        return MPQCrypto::decrypt($data, MPQCrypto::hashString("(hash table)", MPQ_HASH_FILE_KEY));
    }

    public function readBlocktable($block_size)
    {
        $data = array();
        $this->stream->setPosition($this->blockTableOffset);
        
        for ($i = 0; $i < $block_size; $i++)
            $data[] = $this->stream->readUInt32();

        return MPQCrypto::decrypt($data, MPQCrypto::hashString("(block table)", MPQ_HASH_FILE_KEY));
    }

    public function getFileInfo($filename)
    {
        if (!$this->initialized) 
        {
            $this->debugger->write("Archive has not yet been successfully initialized.");
            return false;
        }

        $hash_a     = MPQCrypto::hashString($filename, MPQ_HASH_NAME_A);
        $hash_b     = MPQCrypto::hashString($filename, MPQ_HASH_NAME_B);
        $hash_start = MPQCrypto::hashString($filename, MPQ_HASH_TABLE_OFFSET) & ($this->hashTableSize - 1);
        $block_size = -1;

        $x = $hash_start;

        do 
        {
            if (($this->hashtable[$x*4 + 3] == MPQ_HASH_ENTRY_DELETED) || ($this->hashtable[$x*4 + 3] == MPQ_HASH_ENTRY_EMPTY)) 
            {
                return false;
            }

            if ($this->hashtable[$x*4] == $hash_a && $this->hashtable[$x*4 + 1] == $hash_b) // found file
            {   
                $block_index    = (($this->hashtable[($x *4) + 3]) *4);
                $fp = $this->blockTableOffset;
                $this->blocktable = $this->readBlocktable($block_index + 4);

                $block_offset   = $this->blocktable[$block_index];
                $block_size     = $this->blocktable[$block_index + 1];
                $filesize       = $this->blocktable[$block_index + 2];
                $flags          = $this->blocktable[$block_index + 3];

                $this->blocktable = null;

                break;
            }

            $x = ($x + 1) % $this->hashTableSize;
            $fp = $this->hashTableOffset + $x;

        } while ($x != $hash_start);

        if ($block_size == -1) 
        {
            $this->debugger->write("Did not find file $filename in the archive");
            return false;
        }

        return array('size'=>$block_size, 'index'=>$block_index, 'offset'=>$block_offset, 'filesize'=>$filesize, 'flags'=>$flags);
    }

    public function readFile($filename) 
    {
        // Look for the file in the archive
        $file_info = self::getFileInfo($filename);

        if ($file_info == false) 
            return false;

        $block_size   = $file_info['size'];
        $block_index  = $file_info['index'];
        $block_offset = $file_info['offset'];
        $filesize     = $file_info['filesize'];
        $flags        = $file_info['flags'];

        $flag_file       = $flags & MPQ_FLAG_FILE;
        $flag_checksums  = $flags & MPQ_FLAG_CHECKSUMS;
        $flag_deleted    = $flags & MPQ_FLAG_DELETED;
        $flag_singleunit = $flags & MPQ_FLAG_SINGLEUNIT;
        $flag_hEncrypted = $flags & MPQ_FILE_FIX_KEY;
        $flag_encrypted  = $flags & MPQ_FLAG_ENCRYPTED;
        $flag_compressed = $flags & MPQ_FLAG_COMPRESSED;
        $flag_imploded   = $flags & MPQ_FLAG_IMPLODED;

        $this->debugger->write(sprintf("Found $filename with flags %08X, block offset %08X, block size %d and filesize %d", $flags, $block_offset,$block_size,$filesize));

        if (!$flag_file) 
            return false;

        // Generate an encryption key if the file is encrpyted.
        if ($flag_encrypted)
        {
            $filename = basename(str_replace('\\', '/', $filename));
            $crypt_key = MPQCrypto::hashString($filename, MPQ_HASH_FILE_KEY);

            // Fix the decryption key
            if ($flag_hEncrypted)
                $crypt_key = (($crypt_key + $block_offset) ^ $filesize);
        }

        // Set the offset to the files position in the block table.
        $offset = $this->headerOffset;
        $fp = $block_offset + $offset;
        $this->stream->setPosition($fp);

        // Find the sector offsets.
        if ($flag_singleunit != true && ($flag_checksums || $flag_compressed))
        {
            $sector_count = ceil((double)$filesize / (double)$this->sectorSize);

            for ($i = 0; $i <= $sector_count; $i++) 
            {
                $sector_offsets[$i] = $this->stream->readUInt32($this->file, $fp);
                $block_size -= 4;
            }
        }
        else
        {
            $sector_offsets[] = 0;
            $sector_offsets[] = $block_size;
            $sector_count     = count($sector_offsets)-1;
        }

        // Decrypt the offsets if they are encrypted.
        if ($flag_encrypted)
            $sector_offsets = MPQCrypto::decrypt($sector_offsets, uPlus($crypt_key, -1));

        $output = "";

        // Loop through all of the sectors.
        for ($i = 0; $i < $sector_count; $i++) 
        {
            $sector_len = $sector_offsets[$i + 1] - $sector_offsets[$i];

            if ($sector_len == 0)
                $sector_len = $block_size;

            if ($sector_len == 0) 
                break;

            // Find the sector's position in the block table.
            $fp = ($block_offset + $offset) + $sector_offsets[$i];

            $this->stream->setPosition($fp);

            // Decrypt the sector if it has the encrypted flag.
            if ($flag_encrypted) 
            {
                $sector = array();
                $sector_len >>= 2;

                if ($sector_len > $filesize)
                    return false;

                // Unpack and store the encrypted sector data.
                for($x=0; $x<=$sector_len; $x++)
                    $sector[] = $this->stream->readUInt32($this->file, $fp); // store it

                // Decrypt the sector data and re-pack it.
                $sector = MPQCrypto::decrypt($sector, (int)($crypt_key + $i));

                for($x=0; $x<count($sector); $x++)
                    $sector[$x] = pack("V", $sector[$x]);

                $sector_data = implode($sector);
            }
            else
            {
                $sector_data = $this->stream->readBytes($sector_len);
            }

            $len = strlen($sector_data);
            $this->debugger->write(sprintf("Got %d bytes of sector data", $len));

            if ($len <= 0)
                continue;

            // Decompress the sector data if the compressed flag is found.
            if ($flag_compressed)
            {
                $num_byte         = 0;
                $compression_type = unpack("C", substr($sector_data, 0, 1))[1];  
                $sector_trimmed   = substr($sector_data,1);  

                $this->debugger->write(sprintf("Found compresstion type: %d", $compression_type));

                switch ($compression_type) 
                {
                    case MPQ_COMPRESS_DEFLATE:
                    default:
                        $try_gzip = true;
                        break;

                    case MPQ_COMPRESSION_BZIP2:
                        $decompressed = bzdecompress($sector_trimmed);      

                        if ($decompressed < 0)
                        {
                            $try_gzip = true;
                            $this->debugger->write("Failed to decompress with bzip2, trying gzip...");
                        }
                        else
                        {
                            $this->debugger->write("Decompressed with bzip2");
                            $try_gzip = false;
                            $output .= $decompressed;
                        }

                        break;
                }

                // This isn't part of the switch statement because we want to
                // try gzip if one of the previous compressions fail.
                if ($try_gzip)
                { 
                    $decompressed = ($len < 3 ? false : gzinflate(substr($sector_data, 3, $len - 2)));

                    if (!$decompressed)
                    {
                        $this->debugger->write("Failed to decompress with gzip");
                        $output .= $sector_data;
                    }
                    else
                    {
                        $this->debugger->write("Decompressed with gzip");
                        $output .= $decompressed;
                    }

                }

            }
            else $output .= $sector_data;
        }

        if (strlen($output) != $filesize) 
        {
            $this->debugger->write(sprintf("Decrypted/uncompressed filesize(%d) does not match original file size(%d)", strlen($output), $filesize));
            return false;
        }

        return $output;
    }

}

?>