<?php
App::uses('HttpSocket', 'Network/Http');
/**
 * This file is a part of UploadPack - a plugin that makes file uploads in CakePHP as easy as possible.
 *
 * UploadBehavior
 *
 * UploadBehavior does all the job of saving files to disk while saving records to database. For more info read UploadPack documentation.
 *
 * joe bartlett's lovingly handcrafted tweaks add several resize modes. see "more on styles" in the documentation.
 *
 * @author Michał Szajbe (michal.szajbe@gmail.com) and joe bartlett (contact@jdbartlett.com)
 * @link http://github.com/szajbus/uploadpack
 */
class UploadBehavior extends ModelBehavior {

    private static $__settings = array();

    private $toWrite = array();

    private $toDelete = array();

    private $maxWidthSize = false;

    public function setup(&$model, $settings = array()) {
        $defaults = array(
            'path' => ':webroot/upload/:model/:id/:basename_:style.:extension',
            'styles' => array(),
            'resizeToMaxWidth' => false,
            'quality' => 75
        );

        foreach ($settings as $field => $array) {
            self::$__settings[$model->name][$field] = array_merge($defaults, $array);
        }
    }

    public function beforeSave(&$model) {
        $this->_reset();
        foreach (self::$__settings[$model->name] as $field => $settings) {
            if (!empty($model->data[$model->name][$field]) && is_array($model->data[$model->name][$field]) && file_exists($model->data[$model->name][$field]['tmp_name'])) {
                if (!empty($model->id)) {
                    $this->_prepareToDeleteFiles($model, $field, true);
                }
                $this->_prepareToWriteFiles($model, $field);
                unset($model->data[$model->name][$field]);
                $model->data[$model->name][$field.'_file_name'] = $this->toWrite[$field]['name'];
                $model->data[$model->name][$field.'_file_size'] = $this->toWrite[$field]['size'];
                $model->data[$model->name][$field.'_content_type'] = $this->toWrite[$field]['type'];
            }
        }
        return true;
    }

    public function afterSave(&$model, $create) {
        if (!$create) {
            $this->_deleteFiles($model);
        }
        $this->_writeFiles($model);
    }

    public function beforeDelete(&$model) {
        $this->_reset();
        $this->_prepareToDeleteFiles($model);
        return true;
    }

    public function afterDelete(&$model) {
        $this->_deleteFiles($model);
    }

    public function beforeValidate(&$model) {
        foreach (self::$__settings[$model->name] as $field => $settings) {
            if (isset($model->data[$model->name][$field])) {
                $data = $model->data[$model->name][$field];

                if ((empty($data) || is_array($data) && empty($data['tmp_name'])) && !empty($settings['urlField']) && !empty($model->data[$model->name][$settings['urlField']])) {
                    $data = $model->data[$model->name][$settings['urlField']];
                }

                if (!is_array($data)) {
                    $model->data[$model->name][$field] = $this->_fetchFromUrl($data);
                }
            }
        }
        return true;
    }

    private function _reset() {
        $this->toWrite = null;
        $this->toDelete = null;
    }

    private function _fetchFromUrl($url) {
        $data = array('remote' => true);
        $data['name'] = end(explode('/', $url));
        $data['tmp_name'] = tempnam(sys_get_temp_dir(), $data['name']) . '.' . end(explode('.', $url));

        $httpSocket = new HttpSocket();
        $raw = $httpSocket->get($url);
        $response = $httpSocket->response;
        $data['size'] = strlen($raw);
        $data['type'] = reset(explode(';', $response['header']['Content-Type']));

        file_put_contents($data['tmp_name'], $raw);
        return $data;
    }

    private function _prepareToWriteFiles(&$model, $field) {
        $this->toWrite[$field] = $model->data[$model->name][$field];
        // make filename URL friendly by using Cake's Inflector
        $this->toWrite[$field]['name'] =
            Inflector::slug(substr($this->toWrite[$field]['name'], 0, strrpos($this->toWrite[$field]['name'], '.'))). // filename
            substr($this->toWrite[$field]['name'], strrpos($this->toWrite[$field]['name'], '.')); // extension
    }

    private function _writeFiles(&$model) {
        if (!empty($this->toWrite)) {
            foreach ($this->toWrite as $field => $toWrite) {
                $settings = $this->_interpolate($model, $field, $toWrite['name'], 'original');
                $destDir = dirname($settings['path']);
                if (!file_exists($destDir)) {
                    @mkdir($destDir, 0777, true);
                    @chmod($destDir, 0777);
                }
                if (is_dir($destDir) && is_writable($destDir)) {
                    $move = !empty($toWrite['remote']) ? 'rename' : 'move_uploaded_file';
                    if (@$move($toWrite['tmp_name'], $settings['path'])) {
                        if($this->maxWidthSize) {
                            $this->_resize($settings['path'], $settings['path'], $this->maxWidthSize.'w', $settings['quality']);
                        }
                        foreach ($settings['styles'] as $style => $geometry) {
                            $newSettings = $this->_interpolate($model, $field, $toWrite['name'], $style);
                            $this->_resize($settings['path'], $newSettings['path'], $geometry, $settings['quality']);
                        }
                    }
                }
            }
        }
    }

    private function _prepareToDeleteFiles(&$model, $field = null, $forceRead = false) {
        $needToRead = true;
        if ($field === null) {
            $fields = array_keys(self::$__settings[$model->name]);
            foreach ($fields as &$field) {
                $field .= '_file_name';
            }
        } else {
            $field .= '_file_name';
            $fields = array($field);
        }

        if (!$forceRead && !empty($model->data[$model->alias])) {
            $needToRead = false;
            foreach ($fields as $field) {
                if (!array_key_exists($field, $model->data[$model->alias])) {
                    $needToRead = true;
                    break;
                }
            }
        }
        if ($needToRead) {
            $data = $model->find('first', array('conditions' => array($model->alias.'.'.$model->primaryKey => $model->id), 'fields' => $fields, 'callbacks' => false));
        } else {
            $data = $model->data;
        }
        if (is_array($this->toDelete)) {
            $this->toDelete = array_merge($this->toDelete, $data[$model->alias]);
        } else {
            $this->toDelete = $data[$model->alias];
        }
        $this->toDelete['id'] = $model->id;
    }

    private function _deleteFiles(&$model) {
        foreach (self::$__settings[$model->name] as $field => $settings) {
            if (!empty($this->toDelete[$field.'_file_name'])) {
                $styles = array_keys($settings['styles']);
                $styles[] = 'original';
                foreach ($styles as $style) {
                    $settings = $this->_interpolate($model, $field, $this->toDelete[$field.'_file_name'], $style);
                    if (file_exists($settings['path'])) {
                        @unlink($settings['path']);
                    }
                }
            }
        }
    }

    private function _interpolate(&$model, $field, $filename, $style) {
        return self::interpolate($model->name, $model->id, $field, $filename, $style);
    }

    static public function interpolate($modelName, $modelId, $field, $filename, $style = 'original', $defaults = array()) {
        $pathinfo = UploadBehavior::_pathinfo($filename);
        $interpolations = array_merge(array(
            'app' => preg_replace('/\/$/', '', APP),
            'webroot' => preg_replace('/\/$/', '', WWW_ROOT),
            'model' => Inflector::tableize($modelName),
            'basename' => !empty($filename) ? $pathinfo['filename'] : null,
            'extension' => !empty($filename) ? $pathinfo['extension'] : null,
            'id' => $modelId,
            'style' => $style,
            'attachment' => Inflector::pluralize($field),
            'hash' => md5((!empty($filename) ? $pathinfo['filename'] : "") . Configure::read('Security.salt'))
        ), $defaults);
        $settings = self::$__settings[$modelName][$field];
        $keys = array('path', 'url', 'default_url');
        foreach ($interpolations as $k => $v) {
            foreach ($keys as $key) {
                if (isset($settings[$key])) {
                    $settings[$key] = preg_replace('/\/{2,}/', '/', str_replace(":$k", $v, $settings[$key]));
                }
            }
        }
        return $settings;
    }

    static private function _pathinfo($filename) {
        $pathinfo = pathinfo($filename);
        // PHP < 5.2.0 doesn't include 'filename' key in pathinfo. Let's try to fix this.
        if (empty($pathinfo['filename'])) {
            $suffix = !empty($pathinfo['extension']) ? '.'.$pathinfo['extension'] : '';
            $pathinfo['filename'] = basename($pathinfo['basename'], $suffix);
        }
        return $pathinfo;
    }

    private function _resize($srcFile, $destFile, $geometry, $quality = 75) {
        copy($srcFile, $destFile);
        @chmod($destFile, 0777);
        $pathinfo = UploadBehavior::_pathinfo($srcFile);
        $src = null;
        $createHandler = null;
        $outputHandler = null;
        switch (strtolower($pathinfo['extension'])) {
        case 'gif':
            $createHandler = 'imagecreatefromgif';
            $outputHandler = 'imagegif';
            break;
        case 'jpg':
        case 'jpeg':
            $createHandler = 'imagecreatefromjpeg';
            $outputHandler = 'imagejpeg';
            break;
        case 'png':
            $createHandler = 'imagecreatefrompng';
            $outputHandler = 'imagepng';
            $quality = null;
            break;
        default:
            return false;
        }
        if ($src = $createHandler($destFile)) {
            $srcW = imagesx($src);
            $srcH = imagesy($src);

            // determine destination dimensions and resize mode from provided geometry
            if (preg_match('/^\\[[\\d]+x[\\d]+\\]$/', $geometry)) {
                // resize with banding
                list($destW, $destH) = explode('x', substr($geometry, 1, strlen($geometry)-2));
                $resizeMode = 'band';
            } elseif (preg_match('/^[\\d]+x[\\d]+$/', $geometry)) {
                // cropped resize (best fit)
                list($destW, $destH) = explode('x', $geometry);
                $resizeMode = 'best';
            } elseif (preg_match('/^[\\d]+w$/', $geometry)) {
                // calculate heigh according to aspect ratio
                $destW = (int)$geometry-1;
                $resizeMode = false;
            } elseif (preg_match('/^[\\d]+h$/', $geometry)) {
                // calculate width according to aspect ratio
                $destH = (int)$geometry-1;
                $resizeMode = false;
            } elseif (preg_match('/^[\\d]+l$/', $geometry)) {
                // calculate shortest side according to aspect ratio
                if ($srcW > $srcH) $destW = (int)$geometry-1;
                else $destH = (int)$geometry-1;
                $resizeMode = false;
            }
            if (!isset($destW)) $destW = ($destH/$srcH) * $srcW;
            if (!isset($destH)) $destH = ($destW/$srcW) * $srcH;

            // determine resize dimensions from appropriate resize mode and ratio
            if ($resizeMode == 'best') {
                // "best fit" mode
                if ($srcW > $srcH) {
                    if ($srcH/$destH > $srcW/$destW) $ratio = $destW/$srcW;
                    else $ratio = $destH/$srcH;
                } else {
                    if ($srcH/$destH < $srcW/$destW) $ratio = $destH/$srcH;
                    else $ratio = $destW/$srcW;
                }
                $resizeW = $srcW*$ratio;
                $resizeH = $srcH*$ratio;
            }
            elseif ($resizeMode == 'band') {
                // "banding" mode
                if ($srcW > $srcH) $ratio = $destW/$srcW;
                else $ratio = $destH/$srcH;
                $resizeW = $srcW*$ratio;
                $resizeH = $srcH*$ratio;
            }
            else {
                // no resize ratio
                $resizeW = $destW;
                $resizeH = $destH;
            }

            $img = imagecreatetruecolor($destW, $destH);
            imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
            imagecopyresampled($img, $src, ($destW-$resizeW)/2, ($destH-$resizeH)/2, 0, 0, $resizeW, $resizeH, $srcW, $srcH);
            $outputHandler($img, $destFile, $quality);
            return true;
        }
        return false;
    }

    public function attachmentMinSize(&$model, $value, $min) {
        $value = array_shift($value);
        if (!empty($value['tmp_name'])) {
            return (int)$min <= (int)$value['size'];
        }
        return true;
    }

    public function attachmentMaxSize(&$model, $value, $max) {
        $value = array_shift($value);
        if (!empty($value['tmp_name'])) {
            return (int)$value['size'] <= (int)$max;
        }
        return true;
    }

    public function attachmentContentType(&$model, $value, $contentTypes) {
        $value = array_shift($value);
        if (!is_array($contentTypes)) {
            $contentTypes = array($contentTypes);
        }
        if (!empty($value['tmp_name'])) {
            foreach ($contentTypes as $contentType) {
                if (substr($contentType, 0, 1) == '/') {
                    if (preg_match($contentType, $value['type'])) {
                        return true;
                    }
                } elseif ($contentType == $value['type']) {
                    return true;
                }
            }
            return false;
        }
        return true;
    }

    public function attachmentPresence(&$model, $value) {
        $keys = array_keys($value);
        $field = $keys[0];
        $value = array_shift($value);

        if (!empty($value['tmp_name'])) {
            return true;
        }

        if (!empty($model->id)) {
            if (!empty($model->data[$model->alias][$field.'_file_name'])) {
                return true;
            } elseif (!isset($model->data[$model->alias][$field.'_file_name'])) {
                $existingFile = $model->field($field.'_file_name', array($model->primaryKey => $model->id));
                if (!empty($existingFile)) {
                    return true;
                }
            }
        }
        return false;
    }
    public function minWidth(&$model, $value, $minWidth) {
        return $this->_validateDimension($value, 'min', 'x', $minWidth);
    }

    public function minHeight(&$model, $value, $minHeight) {
        return $this->_validateDimension($value, 'min', 'y', $minHeight);
    }

    public function maxWidth(&$model, $value, $maxWidth) {
        $keys = array_keys($value);
        $field = $keys[0];
        $settings = self::$__settings[$model->name][$field];
        if($settings['resizeToMaxWidth'] && !$this->_validateDimension($value, 'max', 'x', $maxWidth)) {
            $this->maxWidthSize = $maxWidth;
            return true;
        } else {
            return $this->_validateDimension($value, 'max', 'x', $maxWidth);
        }
    }

    public function maxHeight(&$model, $value, $maxHeight) {
        return $this->_validateDimension($value, 'max', 'y', $maxHeight);
    }

    private function _validateDimension($upload, $mode, $axis, $value) {
        $upload = array_shift($upload);
        $func = 'images'.$axis;
        if(!empty($upload['tmp_name'])) {
            $createHandler = null;
            if($upload['type'] == 'image/jpeg') {
                $createHandler = 'imagecreatefromjpeg';
            } else if($upload['type'] == 'image/gif') {
                $createHandler = 'imagecreatefromgif';
            } else if($upload['type'] == 'image/png') {
                $createHandler = 'imagecreatefrompng';
            } else {
                return false;
            }

            if($img = $createHandler($upload['tmp_name'])) {
                switch ($mode) {
                case 'min':
                    return $func($img) >= $value;
                    break;
                case 'max':
                    return $func($img) <= $value;
                    break;
                }
            }
        }
        return false;
    }
}
