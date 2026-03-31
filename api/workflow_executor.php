<?php
header("Content-Type: text/plain; charset=utf-8");
require_once 'env.local.php';

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    function finishRequest($pdo, $id, $title, $comment, $historyTitle, $category) {
        // 1. Set Status to 'Testing'
        $stmt = $pdo->prepare("UPDATE changerequest_log SET status = 'Testing', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        
        // Log to audit history
        $stmt = $pdo->prepare("INSERT INTO changerequest_history (request_id, user_id, username, change_type, old_value, new_value) VALUES (?, 0, 'Agent', 'status', '?', 'Testing')");
        $stmt->execute([$id]);

        // 2. Add Comment
        $stmt = $pdo->prepare("INSERT INTO changerequest_comments (request_id, user_id, username, comment) VALUES (?, 0, 'Agent', ?)");
        $stmt->execute([$id, $comment]);
        
        // 3. Global History
        $stmt = $pdo->prepare("INSERT INTO development_history (date, title, description, category, related_task_id) VALUES (CURDATE(), ?, ?, ?, ?)");
        $stmt->execute([$historyTitle, $comment, $category, $id]);
        
        echo "✅ Request #$id finished.\n";
    }

    echo "--- PROCESSING WORKFLOW ---\n";

    // Request 4
    finishRequest($pdo, 4, "Úprava formuláře Správa požadavku", 
        "Implementována kompletní rekonstrukce v ADO stylu (šířka 1400px), vizuální HTML editor s podporou Ctrl+V a změnou velikosti obrázků, inline editace předmětu, reakce smajlíky a nové stavy (Testing AI, Duplicity atd.).", 
        "Vylepšení Správy požadavků (ADO style)", "feature");

    // Request 17
    finishRequest($pdo, 17, "Přílohy", 
        "Implementována nová sekce 'Přílohy' v pravém panelu. Umožňuje zobrazení souborů nahraných při založení i nahrávání nových příloh k existujícímu požadavku. Každý upload je logován do historie.", 
        "Správa příloh v detailu požadavku", "improvement");

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
