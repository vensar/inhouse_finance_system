<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/layout.php";
requireLogin();
$user = getUserData($_SESSION["user_id"]);
$contacts = ecChatContacts($user);
$csrf = generateCSRFToken();
$peerId = (int) ($_GET["peer"] ?? ($_GET["admin_id"] ?? 0));
$peer = null;
$error = "";
if ($peerId) {
    try {
        $peer = ecChatPeer($user, $peerId);
    } catch (DomainException $e) {
        $error = $e->getMessage();
    }
}
session_write_close();
ecHeader("Messages", "chat");
?>
<div class="page-heading">
    <div>
        <div class="eyebrow">Team communication</div>
        <h1>Messages</h1>
        <p class="muted"><?= isAdmin()
            ? "Respond to district users or start a conversation."
            : "Contact an administrator for assistance." ?></p>
    </div>
</div>
<section class="panel chat-layout">
    <aside class="contacts" aria-label="Message contacts">
        <div class="panel-heading"><h2><?= isAdmin()
            ? "District users"
            : "Administrators" ?></h2></div>
        <?php
        foreach ($contacts as $c): ?>
        <a
            class="contact <?= $peerId == $c["id"] ? "active" : "" ?>"
            href="chat?peer=<?= (int) $c["id"] ?>"
            data-peer="<?= (int) $c["id"] ?>"
        >
            <?php if ($c["unread"]): ?>
            <span class="badge bg-danger"><?= (int) $c["unread"] ?></span>
            <?php endif; ?>
            <strong><?= ecEscape($c["full_name"]) ?></strong>
            <small><?= ecEscape($c["district_name"] ?: "Administrator") ?></small>
        </a>
        <?php endforeach;
if (!$contacts): ?>
        <p class="empty">No active contacts yet.</p>
        <?php endif;
?>
    </aside>
    <div class="conversation">
        <?php if ($peer): ?>
        <div class="conversation-title"><?= ecEscape($peer["full_name"]) ?></div>
        <div class="messages" id="messages" role="log" aria-live="polite" aria-label="Conversation">
            <button class="btn btn-outline-secondary" id="older" hidden>
                Load earlier messages
            </button>
            <div id="message-list"></div>
        </div>
        <p id="chat-error" class="chat-error" role="status"></p>
        <form id="composer" class="composer">
            <input type="hidden" name="csrf_token" value="<?= ecEscape($csrf) ?>" />
            <label class="visually-hidden" for="message">Your message</label>
            <textarea
                class="form-control"
                id="message"
                name="message"
                maxlength="4000"
                rows="2"
                placeholder="Write a message…"
                required
            ></textarea>
            <button class="btn btn-primary" type="submit">Send</button>
        </form>
        <?php else: ?>
        <div class="empty"><?= ecEscape(
            $error ?: "Choose a contact to open a conversation.",
        ) ?></div>
        <?php endif; ?>
    </div>
</section>
<script>
    window.ecChat = <?= json_encode(
        ["peer" => $peer ? (int) $peer["id"] : 0, "user" => (int) $user["id"], "csrf" => $csrf],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
    ) ?>;
</script>
<script src="assets/chat.js" defer></script>
<?php ecFooter(); ?>
