<?php
/**
 *
 * This file is part of phpFastCache.
 *
 * @license MIT License (MIT)
 *
 * For full copyright and license information, please see the docs/CREDITS.txt file.
 *
 * @author Khoa Bui (khoaofgod)  <khoaofgod@gmail.com> http://www.phpfastcache.com
 * @author Georges.L (Geolim4)  <contact@geolim4.com>
 *
 */

namespace phpFastCache\Core\Pool\IO;

use phpFastCache\Core\Pool\ExtendedCacheItemPoolInterface;
use phpFastCache\Exceptions\phpFastCacheIOException;
use phpFastCache\Util\Directory;

trait IOHelperTrait
{
    /**
     * @var array
     */
    public $tmp = [];

    /**
     * @param bool $readonly
     * @return string
     * @throws phpFastCacheIOException
     */
    public function getPath($readonly = false)
    {
        /**
         * Get the base system temporary directory
         */
        $tmp_dir = rtrim(ini_get('upload_tmp_dir') ?: sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'phpfastcache';

        /**
         * Calculate the security key
         */
        {
            $securityKey = array_key_exists('securityKey', $this->config) ? $this->config[ 'securityKey' ] : '';
            if (!$securityKey || $securityKey === 'auto') {
                if (isset($_SERVER[ 'HTTP_HOST' ])) {
                    $securityKey = preg_replace('/^www./', '', strtolower(str_replace(':', '_', $_SERVER[ 'HTTP_HOST' ])));
                } else {
                    $securityKey = ($this->isPHPModule() ? 'web' : 'cli');
                }
            }

            if ($securityKey !== '') {
                $securityKey .= '/';
            }

            $securityKey = static::cleanFileName($securityKey);
        }

        /**
         * Extends the temporary directory
         * with the security key and the driver name
         */
        $tmp_dir = rtrim($tmp_dir, '/') . DIRECTORY_SEPARATOR;

        if (empty($this->config[ 'path' ]) || !is_string($this->config[ 'path' ])) {
            if (self::isPHPModule()) {
                $path = $tmp_dir;
            } else {
                $document_root_path = rtrim($_SERVER[ 'DOCUMENT_ROOT' ], '/') . '/../';
                $path = isset($_SERVER[ 'DOCUMENT_ROOT' ]) && is_writable($document_root_path) ? $document_root_path : rtrim(__DIR__, '/') . DIRECTORY_SEPARATOR;
            }

            if ($this->config[ 'path' ] != '') {
                $path = $this->config[ 'path' ];
            }

        } else {
            $path = $this->config[ 'path' ];
        }

        $full_path = rtrim($path, '/')
          . DIRECTORY_SEPARATOR
          . $securityKey
          . DIRECTORY_SEPARATOR
          . $this->getDriverName();
        $full_path_hash = md5($full_path);

        /**
         * In readonly mode we only attempt
         * to verify if the directory exists
         * or not, if it does not then we
         * return the temp dir
         */
        if ($readonly === true) {
            if($this->config[ 'autoTmpFallback' ] && (@file_exists($full_path) || !@is_writable($full_path))){
                return $tmp_dir;
            }
            return $full_path;
        }else{
            if (!isset($this->tmp[ $full_path_hash ]) || (!@file_exists($full_path) || !@is_writable($full_path))) {
                if (!@file_exists($full_path)) {
                    @mkdir($full_path, $this->setChmodAuto(), true);
                }else if (!@is_writable($full_path)) {
                    @chmod($full_path, $this->setChmodAuto());
                }

                if ($this->config[ 'autoTmpFallback' ] && !@is_writable($full_path)) {
                    /**
                     * Switch back to tmp dir
                     * again if the path is not writable
                     */
                    $full_path = $tmp_dir;
                    if (!@file_exists($full_path)) {
                        @mkdir($full_path, $this->setChmodAuto(), true);
                    }
                }

                /**
                 * In case there is no directory
                 * writable including tye temporary
                 * one, we must throw an exception
                 */
                if (!@file_exists($full_path) || !@is_writable($full_path)) {
                    throw new phpFastCacheIOException('PLEASE CREATE OR CHMOD ' . $full_path . ' - 0777 OR ANY WRITABLE PERMISSION!');
                }

                $this->tmp[ $full_path_hash ] = $full_path;
                $this->htaccessGen($full_path, array_key_exists('htaccess', $this->config) ? $this->config[ 'htaccess' ] : false);
            }
        }

        return realpath($full_path);
    }


    /**
     * @param $keyword
     * @param bool $skip
     * @return string
     * @throws phpFastCacheIOException
     */
    private function getFilePath($keyword, $skip = false)
    {
        $path = $this->getPath();

        if ($keyword === false) {
            return $path;
        }

        $filename = $this->encodeFilename($keyword);
        $folder = substr($filename, 0, 2) . DIRECTORY_SEPARATOR . substr($filename, 2, 2);
        $path = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . $folder;

        /**
         * Skip Create Sub Folders;
         */
        if ($skip == false) {
            if (!file_exists($path)) {
                if (@!mkdir($path, $this->setChmodAuto(), true)) {
                    throw new phpFastCacheIOException('PLEASE CHMOD ' . $path . ' - ' . $this->setChmodAuto() . ' OR ANY WRITABLE PERMISSION!');
                }
            }
        }

        return $path . '/' . $filename . '.txt';
    }



    /**
     * @param $keyword
     * @return string
     */
    protected function encodeFilename($keyword)
    {
        return md5($keyword);
    }

    /**
     * @return bool
     */
    public function isExpired()
    {
        trigger_error(__FUNCTION__ . '() is deprecated, use ExtendedCacheItemInterface::isExpired() instead.', E_USER_DEPRECATED);

        return true;
    }

    /**
     * @param $this ->config
     * @return int
     */
    public function setChmodAuto()
    {
        if (!isset($this->config[ 'default_chmod' ]) || $this->config[ 'default_chmod' ] == '' || is_null($this->config[ 'default_chmod' ])) {
            return 0777;
        } else {
            return $this->config[ 'default_chmod' ];
        }
    }

    /**
     * @param $filename
     * @return mixed
     */
    protected static function cleanFileName($filename)
    {
        $regex = [
          '/[\?\[\]\/\\\=\<\>\:\;\,\'\"\&\$\#\*\(\)\|\~\`\!\{\}]/',
          '/\.$/',
          '/^\./',
        ];
        $replace = ['-', '', ''];

        return trim(preg_replace($regex, $replace, trim($filename)), '-');
    }

    /**
     * @param $path
     * @param bool $create
     * @throws phpFastCacheIOException
     */
    protected function htaccessGen($path, $create = true)
    {
        if ($create === true) {
            if (!is_writable($path)) {
                try {
                    if(!chmod($path, 0777)){
                        throw new phpFastCacheIOException('Chmod failed on : ' . $path);
                    }
                } catch (phpFastCacheIOException $e) {
                    throw new phpFastCacheIOException('PLEASE CHMOD ' . $path . ' - 0777 OR ANY WRITABLE PERMISSION!', 0, $e);
                }
            }

            if (!file_exists($path . "/.htaccess")) {
                $content = <<<HTACCESS
### This .htaccess is auto-generated by PhpFastCache ###
order deny, allow
deny from all
allow from 127.0.0.1
HTACCESS;

                $file = @fopen($path . '/.htaccess', 'w+');
                if (!$file) {
                    throw new phpFastCacheIOException('PLEASE CHMOD ' . $path . ' - 0777 OR ANY WRITABLE PERMISSION!');
                }
                fwrite($file, $content);
                fclose($file);
            }
        }
    }


    /**
     * @param $file
     * @return string
     * @throws phpFastCacheIOException
     */
    protected function readfile($file)
    {
        if (function_exists('file_get_contents')) {
            return file_get_contents($file);
        } else {
            $string = '';

            $file_handle = @fopen($file, 'r');
            if (!$file_handle) {
                throw new phpFastCacheIOException("Cannot read file located at: {$file}");
            }
            while (!feof($file_handle)) {
                $line = fgets($file_handle);
                $string .= $line;
            }
            fclose($file_handle);

            return $string;
        }
    }

    /**
     * @param string $file
     * @param string $data
     * @param bool $secureFileManipulation
     * @return bool
     * @throws phpFastCacheIOException
     */
    protected function writefile($file, $data, $secureFileManipulation = false)
    {
        /**
         * @eventName CacheWriteFileOnDisk
         * @param ExtendedCacheItemPoolInterface $this
         * @param string $file
         * @param bool $secureFileManipulation
         *
         */
        $this->eventManager->dispatch('CacheWriteFileOnDisk', $this, $file, $secureFileManipulation);

        if($secureFileManipulation){
            $tmpFilename = Directory::getAbsolutePath(dirname($file) . '/tmp_' . md5(
                str_shuffle(uniqid($this->getDriverName(), false))
                . str_shuffle(uniqid($this->getDriverName(), false))
              ));

            $f = fopen($tmpFilename, 'w+');
            flock($f, LOCK_EX);
            $octetWritten = fwrite($f, $data);
            flock($f, LOCK_UN);
            fclose($f);

            if(!rename($tmpFilename, $file)){
                throw new phpFastCacheIOException(sprintf('Failed to rename %s to %s', $tmpFilename, $file));
            }
        }else{
            $f = fopen($file, 'w+');
            $octetWritten = fwrite($f, $data);
            fclose($f);
        }

        return $octetWritten !== false;
    }
}