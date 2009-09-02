<?php
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
  
  static $__settings = array();
  
  var $toWrite = array();
  
  var $toDelete = array();
  
  function setup(&$model, $settings = array()) {
    $defaults = array(
      'path' => ':webroot/upload/:model/:id/:basename_:style.:extension',
      'styles' => array()
    );
    
    foreach ($settings as $field => $array) {
      self::$__settings[$model->name][$field] = array_merge($defaults, $array);
    }
  }
  
  function beforeSave(&$model) {
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
  
  function afterSave(&$model, $create) {
    if (!$create) {
      $this->_deleteFiles($model);
    }
    $this->_writeFiles($model);
  }
  
  function beforeDelete(&$model) {
    $this->_reset();
    $this->_prepareToDeleteFiles($model);
    return true;
  }
  
  function afterDelete(&$model) {
    $this->_deleteFiles($model);
  }
  
  function _reset() {
    $this->_toWrite = null;
    $this->_toDelete = null;
  }
  
  function _prepareToWriteFiles(&$model, $field) {
    $this->toWrite[$field] = $model->data[$model->name][$field];
  }
  
  function _writeFiles(&$model) {
    if (!empty($this->toWrite)) {
      foreach ($this->toWrite as $field => $toWrite) {
        $settings = $this->_interpolate($model, $field, $toWrite['name'], 'original');
        $destDir = dirname($settings['path']);
        if (!file_exists($destDir)) {
          @mkdir($destDir, 0777, true);
          @chmod($destDir, 0777);
        }
        if (is_dir($destDir) && is_writable($destDir)) {
          if (@move_uploaded_file($toWrite['tmp_name'], $settings['path'])) {
            foreach ($settings['styles'] as $style => $geometry) {
              $newSettings = $this->_interpolate($model, $field, $toWrite['name'], $style);
              $this->_resize($settings['path'], $newSettings['path'], $geometry);
            }
          }
        }
      }
    }
  }
  
  function _prepareToDeleteFiles(&$model, $field = null, $forceRead = false) {
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
  
  function _deleteFiles(&$model) {
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
  
  function _interpolate(&$model, $field, $filename, $style) {
    return self::interpolate($model->name, $model->id, $field, $filename, $style);
  }
  
  static function interpolate($modelName, $modelId, $field, $filename, $style, $defaults = array()) {
    $pathinfo = pathinfo($filename);
    $interpolations = array_merge(array(
      'webroot' => preg_replace('/\/$/', '', WWW_ROOT),
      'model' => Inflector::tableize($modelName),
      'basename' => !empty($filename) ? $pathinfo['filename'] : null,
      'extension' => !empty($filename) ? $pathinfo['extension'] : null,
      'id' => $modelId,
      'style' => $style
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
  
  function _resize($srcFile, $destFile, $geometry) {
    copy($srcFile, $destFile);
    $pathinfo = pathinfo($srcFile);
    $src = null;
    $createHandler = null;
    $outputHandler = null;
    switch (low($pathinfo['extension'])) {
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
        $destW = strlen($geometry)-1;
        $resizeMode = false;
      } elseif (preg_match('/^[\\d]+h$/', $geometry)) {
        // calculate width according to aspect ratio
        $destH = strlen($geometry)-1;
        $resizeMode = false;
      } elseif (preg_match('/^[\\d]+l$/', $geometry)) {
        // calculate shortest side according to aspect ratio
        if ($srcW > $srcH) $destW = strlen($geometry)-1;
        else $destH = strlen($geometry)-1;
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
      $outputHandler($img, $destFile);
      return true;
    }
    return false;
  }
  
  function attachmentMinSize(&$model, $value, $min) {
    $value = array_shift($value);
    if (!empty($value['tmp_name'])) {
      return (int)$min <= (int)$value['size'];
    }
    return true;
  }
  
  function attachmentMaxSize(&$model, $value, $max) {
    $value = array_shift($value);
    if (!empty($value['tmp_name'])) {
      return (int)$value['size'] <= (int)$max;
    }
    return true;
  }
  
  function attachmentContentType(&$model, $value, $contentTypes) {
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
  
  function attachmentPresence(&$model, $value) {
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
}
?>