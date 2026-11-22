<?php
class JsonStorage {
    private string $filepath;

    public function __construct(string $filename = 'appointments.json') {
        $this->filepath = __DIR__ . '/../data/' .$filename;
        
        if (!file_exists(dirname($this->filepath))) {
            mkdir(dirname($this->filepath), 0755, true);
        }
        if (!file_exists($this->filepath)) {
            file_put_contents($this->filepath, json_encode([], JSON_PRETTY_PRINT));
        }
    }

    public function read(): array {
        if (!file_exists($this->filepath)) {
            return [];
        }
        $content = file_get_contents($this->filepath);
        $data = json_decode($content, true);
        return is_array($data) ?$data : [];
    }

    public function atomicInsert(callable $callback) {
        $file = fopen($this->filepath, 'c+');
        if (!$file) return ['success' => false, 'message' => 'Unable to open storage file.'];

        $result = ['success' => false, 'message' => 'Unknown error'];

        if (flock($file, LOCK_EX)) {
            $size = filesize($this->filepath);
            $content =$size > 0 ? fread($file,$size) : '';
            $records = json_decode($content, true);
            if (!is_array($records)) {$records = [];
            }

            $result = $callback($records);

            if ($result['success']) {
                ftruncate($file, 0);
                rewind($file);
                fwrite($file, json_encode($records, JSON_PRETTY_PRINT));
                fflush($file);
            }

            flock($file, LOCK_UN);
        }

        fclose($file);
        return $result;
    }

    public function write(array $records): bool {
        $file = fopen($this->filepath, 'c+');
        if (!$file) return false;

        $success = false;
        if (flock($file, LOCK_EX)) {
            ftruncate($file, 0);
            rewind($file);
            if (fwrite($file, json_encode($records, JSON_PRETTY_PRINT)) !== false) {
                fflush($file);$success = true;
            }
            flock($file, LOCK_UN);
        }

        fclose($file);
        return $success;
    }
}