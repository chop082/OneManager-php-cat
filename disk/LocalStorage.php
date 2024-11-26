<?php
class LocalStorage {
    protected $disktag;
    protected $rootPath;   
    protected $fileTypes;
    public $error;

    function __construct($tag) {
        $this->disktag = $tag;
        $this->rootPath = getConfig('root_path', $tag);
        if ($this->rootPath == '') {
            $this->error = ['stat' => 500, 'body' => '存储根目录未设置'];
            return;
        }
        
        // 标准化路径
        $this->rootPath = realpath($this->rootPath);
        if (!$this->rootPath || !is_dir($this->rootPath)) {
            $this->error = ['stat' => 500, 'body' => '存储根目录无效'];
            return;
        }

        // 检查目录权限
        if (!is_readable($this->rootPath) || !is_writable($this->rootPath)) {
            $this->error = ['stat' => 500, 'body' => '存储目录权限不足'];
            return;
        }
        
        // 默认允许的文件类型
        $this->fileTypes = array_merge(
            ['txt', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'],
            ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'],
            ['mp3', 'wav', 'ogg', 'flac'],
            ['mp4', 'webm', 'mkv']
        );
    }

    public function isfine() {
        return empty($this->error);
    }

    public function show_base_class() {
        return get_class();
    }

    public function ext_show_innerenv() {
        return [
            'root_path',
            'max_size' 
        ];
    }

    public function list_files($path = '/') {
        $physicalPath = $this->getPhysicalPath($path);
        if (!$this->checkPath($physicalPath)) {
            return ['error' => ['code' => 'InvalidPath', 'message' => '路径无效']];
        }

        if (!is_dir($physicalPath)) {
            if (is_file($physicalPath)) {
                return $this->files_format($this->getFileInfo($physicalPath, $path));
            }
            return ['error' => ['code' => 'NotFound', 'message' => '路径不存在']];
        }

        $list = [];
        $files = scandir($physicalPath);
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') continue;
            if ($this->checkPath($physicalPath . '/' . $file)) {
                $list[$file] = $this->getFileInfo($physicalPath . '/' . $file, $path . '/' . $file);
            }
        }

        return $this->files_format([
            'type' => 'folder',
            'name' => basename($path),
            'path' => $path,
            'list' => $list
        ]);
    }

    public function Rename($file, $newname) {
        $oldPath = $this->getPhysicalPath($file['path'] . '/' . $file['name']);
        $newPath = $this->getPhysicalPath(dirname($file['path']) . '/' . $newname);

        if (!$this->checkPath($oldPath) || !$this->checkPath($newPath)) {
            return ['error' => ['code' => 'InvalidPath', 'message' => '路径无效']];
        }

        if (rename($oldPath, $newPath)) {
            return $this->files_format($this->getFileInfo($newPath, dirname($file['path']) . '/' . $newname));
        }
        return ['error' => ['code' => 'RenameFailed', 'message' => '重命名失败']];
    }

    public function Delete($file) {
        $path = $this->getPhysicalPath($file['path'] . '/' . $file['name']);
        if (!$this->checkPath($path)) {
            return ['error' => ['code' => 'InvalidPath', 'message' => '路径无效']];
        }

        if (is_dir($path)) {
            if ($this->deleteDir($path)) {
                return ['stat' => 200, 'body' => '删除成功'];
            }
        } else {
            if (unlink($path)) {
                return ['stat' => 200, 'body' => '删除成功'];
            }
        }
        return ['error' => ['code' => 'DeleteFailed', 'message' => '删除失败']];
    }

    public function Move($file, $folder) {
        $oldPath = $this->getPhysicalPath($file['path'] . '/' . $file['name']);
        $newPath = $this->getPhysicalPath($folder['path'] . '/' . $file['name']);

        if (!$this->checkPath($oldPath) || !$this->checkPath($newPath)) {
            return ['error' => ['code' => 'InvalidPath', 'message' => '路径无效']];
        }

        if (rename($oldPath, $newPath)) {
            return $this->files_format($this->getFileInfo($newPath, $folder['path'] . '/' . $file['name']));
        }
        return ['error' => ['code' => 'MoveFailed', 'message' => '移动失败']];
    }

    public function Copy($file) {
        $sourcePath = $this->getPhysicalPath($file['path'] . '/' . $file['name']);
        $targetPath = $this->getPhysicalPath($file['path'] . '/副本-' . $file['name']);

        if (!$this->checkPath($sourcePath) || !$this->checkPath($targetPath)) {
            return ['error' => ['code' => 'InvalidPath', 'message' => '路径无效']];
        }

        if (is_dir($sourcePath)) {
            if ($this->copyDir($sourcePath, $targetPath)) {
                return $this->files_format($this->getFileInfo($targetPath, $file['path'] . '/副本-' . $file['name']));
            }
        } else {
            if (copy($sourcePath, $targetPath)) {
                return $this->files_format($this->getFileInfo($targetPath, $file['path'] . '/副本-' . $file['name']));
            }
        }
        return ['error' => ['code' => 'CopyFailed', 'message' => '复制失败']];
    }

    public function Create($parent, $type, $name, $content = '') {
        $path = $this->getPhysicalPath($parent['path'] . '/' . $name);
        if (!$this->checkPath($path)) {
            return ['error' => ['code' => 'InvalidPath', 'message' => '路径无效']];
        }

        if ($type == 'folder') {
            if (mkdir($path)) {
                return $this->files_format($this->getFileInfo($path, $parent['path'] . '/' . $name));
            }
        } else {
            if (file_put_contents($path, $content) !== false) {
                return $this->files_format($this->getFileInfo($path, $parent['path'] . '/' . $name));
            }
        }
        return ['error' => ['code' => 'CreateFailed', 'message' => '创建失败']];
    }

    public function Upload($path, $tmpfile) {
        $filename = $tmpfile['name'];
        $targetPath = $this->getPhysicalPath($path . '/' . $filename);

        // 检查文件类型
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, $this->fileTypes)) {
            return ['error' => ['code' => 'InvalidType', 'message' => '文件类型不允许']];
        }

        // 检查文件大小
        $maxSize = getConfig('max_size', $this->disktag) ?: 10 * 1024 * 1024;
        if ($tmpfile['size'] > $maxSize) {
            return ['error' => ['code' => 'FileTooLarge', 'message' => '文件太大']];
        }

        if (move_uploaded_file($tmpfile['tmp_name'], $targetPath)) {
            return $this->files_format($this->getFileInfo($targetPath, $path . '/' . $filename));
        }
        return ['error' => ['code' => 'UploadFailed', 'message' => '上传失败']];
    }

    public function Edit($file, $content) {
        $path = $this->getPhysicalPath($file['path']);
        if (!$this->checkPath($path)) {
            return ['error' => ['code' => 'InvalidPath', 'message' => '路径无效']];
        }

        if (file_put_contents($path, $content) !== false) {
            return ['stat' => 200, 'body' => '编辑成功'];
        }
        return ['error' => ['code' => 'EditFailed', 'message' => '编辑失败']];
    }

    public function bigfileupload($path) {
        if (!isset($_POST['uploadid'])) {
            $uploadid = md5(uniqid(mt_rand(), true));
            $tmpPath = sys_get_temp_dir() . '/onemanager_upload_' . $uploadid;
            
            return [
                'stat' => 200,
                'body' => json_encode([
                    'uploadUrl' => $tmpPath,
                    'uploadId' => $uploadid
                ])
            ];
        } else {
            $input = fopen('php://input', 'rb');
            $uploadid = $_POST['uploadid'];
            $tmpPath = sys_get_temp_dir() . '/onemanager_upload_' . $uploadid;
            
            $fp = fopen($tmpPath, 'ab');
            while (!feof($input)) {
                fwrite($fp, fread($input, 8192));
            }
            fclose($fp);
            fclose($input);

            if (isset($_POST['complete'])) {
                $filename = $_POST['filename'];
                $targetPath = $this->getPhysicalPath($path . '/' . $filename);

                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (!in_array($ext, $this->fileTypes)) {
                    unlink($tmpPath);
                    return ['error' => ['code' => 'InvalidType', 'message' => '文件类型不允许']];
                }

                $maxSize = getConfig('max_size', $this->disktag) ?: 10 * 1024 * 1024;
                if (filesize($tmpPath) > $maxSize) {
                    unlink($tmpPath);
                    return ['error' => ['code' => 'FileTooLarge', 'message' => '文件太大']];
                }

                if (rename($tmpPath, $targetPath)) {
                    return $this->files_format($this->getFileInfo($targetPath, $path . '/' . $filename));
                }
                return ['error' => ['code' => 'UploadFailed', 'message' => '上传失败']];
            }
            return ['stat' => 200, 'body' => 'Continue'];
        }
    }

    public function get_thumbnails_url($path) {
        $physicalPath = $this->getPhysicalPath($path);
        if (!$this->checkPath($physicalPath)) return '';

        $ext = strtolower(pathinfo($physicalPath, PATHINFO_EXTENSION));
        $imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
        if (!in_array($ext, $imageTypes)) return '';

        $thumbDir = $this->rootPath . '/.thumbnails';
        if (!is_dir($thumbDir)) mkdir($thumbDir);
        
        $thumbPath = $thumbDir . '/' . md5($path) . '.' . $ext;
        if (!file_exists($thumbPath) || filemtime($thumbPath) < filemtime($physicalPath)) {
            $this->createThumbnail($physicalPath, $thumbPath);
        }

        return $_SERVER['PHP_SELF'] . '?thumbnail=' . urlencode(substr($thumbPath, strlen($this->rootPath)));
    }

    public function getDiskSpace() {
        $total = disk_total_space($this->rootPath);
        $free = disk_free_space($this->rootPath);
        $used = $total - $free;
        return size_format($used) . ' / ' . size_format($total);
    }

    public function AddDisk() {
        if (isset($_GET['install0'])) {
            $tmp = [];
            $tmp['Driver'] = 'LocalStorage';
            $tmp['diskname'] = $_POST['diskname'];
            $tmp['root_path'] = $_POST['root_path'];
            $tmp['max_size'] = intval($_POST['max_size']) * 1024 * 1024;

            $response = setConfigResponse(setConfig($tmp, $this->disktag));
            return message($response, '设置成功', 201);
        }

        $html = '<form name="form1" method="post" onsubmit="return checkPath(this);">
            <label>存储名称:</label><br>
            <input type="text" name="diskname" placeholder="本地存储"><br>
            <label>根目录路径:</label><br>
            <input type="text" name="root_path" placeholder="/path/to/storage"><br>
            <label>最大文件大小(MB):</label><br>
            <input type="number" name="max_size" value="10"><br>
            <input type="submit" value="提交">
        </form>
        <script>
        function checkPath(f) {
            if (f.root_path.value==\'\') {
                alert(\'请输入根目录路径\');
                return false;
            }
            return true;
        }
        </script>';
        
        return message($html, '设置存储', 201);
    }

    protected function getPhysicalPath($path) {
        return $this->rootPath . '/' . ltrim($path, '/');
    }

    protected function checkPath($path) {
        $realPath = realpath($path);
        if ($realPath === false) return true;
        return strpos($realPath, $this->rootPath) === 0;
    }

    protected function getFileInfo($physicalPath, $virtualPath) {
        $info = [];
        $info['type'] = is_dir($physicalPath) ? 'folder' : 'file';
        $info['name'] = basename($physicalPath);
        $info['path'] = dirname($virtualPath);
        $info['time'] = filemtime($physicalPath);
        $info['size'] = is_dir($physicalPath) ? 0 : filesize($physicalPath);
        if ($info['type'] == 'file') {
            $info['url'] = $this->getDownloadUrl($virtualPath);
            $info['mime'] = mime_content_type($physicalPath);
        }
        return $info;
    }

    protected function getDownloadUrl($path) {
        return $_SERVER['PHP_SELF'] . '?download=' . urlencode($path);
    }

    protected function files_format($files) {
        if (isset($files['error'])) return $files;

        $format = [];
        if ($files['type'] == 'folder') {
            $format['type'] = 'folder';
            $format['name'] = $files['name'];
            $format['time'] = date("Y-m-d H:i:s", $files['time']);
            $format['size'] = 0;
            if (isset($files['list'])) {
                foreach ($files['list'] as $name => $item) {
                    $format['list'][$name] = [
                        'type' => $item['type'],
                        'name' => $item['name'],
                        'time' => date("Y-m-d H:i:s", $item['time']),
                        'size' => $item['size'],
                    ];
                    if ($item['type'] == 'file') {
                        $format['list'][$name]['url'] = $item['url'];
                        $format['list'][$name]['mime'] = $item['mime'];
                    }
                }
            }
        } else {
            $format['type'] = 'file';
            $format['name'] = $files['name'];
            $format['time'] = date("Y-m-d H:i:s", $files['time']);
            $format['size'] = $files['size'];
            $format['url'] = $files['url'];
            $format['mime'] = $files['mime'];
        }
        return $format;
    }

    protected function deleteDir($dir) {
        if (!is_dir($dir)) return false;
        
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item == '.' || $item == '..') continue;
            
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDir($path);
            } else {
                unlink($path);
            }
        }
        return rmdir($dir);
    }

    protected function copyDir($src, $dst) {
        if (!is_dir($dst)) mkdir($dst);
        
        $items = scandir($src);
        foreach ($items as $item) {
            if ($item == '.' || $item == '..') continue;
            
            $srcPath = $src . '/' . $item;
            $dstPath = $dst . '/' . $item;
            
            if (is_dir($srcPath)) {
                $this->copyDir($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
        return true;
    }

    protected function createThumbnail($source, $destination) {
        list($width, $height) = getimagesize($source);
        $maxDim = 150;
        
        $ratio = $width / $height;
        if ($ratio > 1) {
            $new_width = $maxDim;
            $new_height = $maxDim / $ratio;
        } else {
            $new_width = $maxDim * $ratio;
            $new_height = $maxDim;
        }

        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                $src = imagecreatefromjpeg($source);
                break;
            case 'png':
                $src = imagecreatefrompng($source);
                break;
            case 'gif':
                $src = imagecreatefromgif($source);
                break;
            case 'bmp':
                $src = imagecreatefrombmp($source);
                break;
            case 'webp':
                $src = imagecreatefromwebp($source);
                break;
            default:
                return false;
        }

        $dst = imagecreatetruecolor($new_width, $new_height);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                imagejpeg($dst, $destination, 80);
                break;
            case 'png':
                imagepng($dst, $destination, 7);
                break;
            case 'gif':
                imagegif($dst, $destination);
                break;
            case 'bmp':
                imagebmp($dst, $destination);
                break;
            case 'webp':
                imagewebp($dst, $destination, 80);
                break;
        }

        imagedestroy($src);
        imagedestroy($dst);
        return true;
    }
}