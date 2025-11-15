<?php
// 設定頁面：列出所有 Feed 讓使用者勾選要清理的目標
$feedDAO = FreshRSS_Factory::createFeedDAO();
$feeds = $feedDAO->listFeeds();
$selected = $this->config['feeds'] ?? [];
?>
<h2>Google News Clean 設定</h2>
<form method="post">
    <table class="formTable" style="max-width:600px;">
        <thead>
            <tr><th>啟用</th><th>Feed 名稱</th><th>來源 URL</th></tr>
        </thead>
        <tbody>
        <?php foreach ($feeds as $f): ?>
            <?php $checked = in_array((int)$f->id(), $selected, true) ? 'checked' : ''; ?>
            <tr>
                <td><input type="checkbox" name="feeds[]" value="<?php echo (int)$f->id(); ?>" <?php echo $checked; ?>></td>
                <td><?php echo htmlspecialchars($f->name()); ?></td>
                <td style="font-size:0.8em;"><?php echo htmlspecialchars($f->url()); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p><button type="submit" class="btn">儲存設定</button></p>
</form>
<p>只會處理勾選的 Feed 中以 news.google.com 為主機的文章連結，將其還原為原始來源網址。</p>
