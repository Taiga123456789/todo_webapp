<?php
header('Content-Type: application/json');

define('DB_FILE', 'todo.sqlite');
define('DSN', 'sqlite:' . DB_FILE);

function getDbConnection() {
    try {
        $is_new_db = !file_exists(DB_FILE);
        $pdo = new PDO(DSN);
        
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        if ($is_new_db) {
            $sql = "
                CREATE TABLE tasks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    content TEXT NOT NULL,
                    is_completed INTEGER NOT NULL DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ";
            $pdo->exec($sql);
            $pdo->exec("INSERT INTO tasks (content) VALUES ('課題の計画を立てる');");
            $pdo->exec("INSERT INTO tasks (content, is_completed) VALUES ('校友会の起案を出す', 1);");
        }
        
        return $pdo;

    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Internal server error (DB error).']);
        exit;
    }
}

function sendSuccess($data = []) {
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function sendError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$pdo = getDbConnection();

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

try {
    switch ($method) {
        case 'GET':
            $stmt = $pdo->prepare("SELECT id, content, is_completed FROM tasks ORDER BY created_at DESC");
            $stmt->execute();
            $tasks = $stmt->fetchAll();
            foreach ($tasks as &$task) {
                $task['is_completed'] = (bool)$task['is_completed'];
            }
            sendSuccess($tasks);
            break;

        case 'POST':
            if (empty($input['content'])) {
                sendError('タスク内容が空です。');
            }
            $content = mb_substr(trim($input['content']), 0, 255); 

            $stmt = $pdo->prepare("INSERT INTO tasks (content) VALUES (:content)");
            $stmt->bindParam(':content', $content);
            $stmt->execute();

            sendSuccess(['id' => $pdo->lastInsertId(), 'content' => $content]);
            break;

        case 'PATCH':
            if (empty($input['id']) || !isset($input['is_completed'])) {
                sendError('IDまたは完了状態が指定されていません。');
            }
            $id = (int)$input['id'];
            $is_completed = (int)(bool)$input['is_completed']; 

            $stmt = $pdo->prepare("UPDATE tasks SET is_completed = :is_completed WHERE id = :id");
            $stmt->bindParam(':is_completed', $is_completed, PDO::PARAM_INT);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            
            if ($stmt->execute() && $stmt->rowCount() > 0) {
                sendSuccess(['id' => $id, 'is_completed' => $is_completed]);
            } else {
                sendError('タスクの更新に失敗しました。IDが見つからない可能性があります。', 404);
            }
            break;

        case 'DELETE':
            if (empty($input['id'])) {
                sendError('削除するタスクのIDが指定されていません。');
            }
            $id = (int)$input['id'];

            $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            
            if ($stmt->execute() && $stmt->rowCount() > 0) {
                sendSuccess(['id' => $id]);
            } else {
                sendError('タスクの削除に失敗しました。IDが見つからない可能性があります。', 404);
            }
            break;

        default:
            sendError('許可されていないメソッドです。', 405);
            break;
    }

} catch (PDOException $e) {
    error_log("SQL execution error: " . $e->getMessage());
    sendError('データベース処理中にエラーが発生しました。', 500);
}

?>