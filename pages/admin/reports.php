<section class="admin-command-card">
            <div class="admin-card-head">
                <div>
                    <span class="driver-kicker">Moderation</span>
                    <h2>User Reports</h2>
                </div>
                <div class="admin-table-tools">
                    <input type="search" placeholder="Filter reports" aria-label="Filter reports panel" data-admin-panel-search="reportsPanel" data-search-context="reportsPanel">
                </div>
            </div>
            <?php if (count($reportRows) === 0): ?>
                <div class="driver-empty-card">No active reports. System trust health is stable.</div>
            <?php else: ?>
                <div class="admin-report-list" id="reportsPanel">
                    <?php foreach ($reportRows as $report): ?>
                        <?php $reportSearch = ridesync_admin_search_blob([$report['id'], $report['reason'], $report['report_status'], $report['reporter_name'], $report['reported_name'], $report['origin'], $report['destination'], $report['message'], $report['admin_note'] ?? '']); ?>
                        <article class="admin-report-card" data-search="<?php echo htmlspecialchars($reportSearch); ?>">
                            <div class="admin-review-top">
                                <div>
                                    <span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_status_class($report['report_status'])); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($report['report_status'])); ?></span>
                                    <h3><?php echo htmlspecialchars(ridesync_admin_status_label($report['reason'])); ?></h3>
                                    <p>From <?php echo htmlspecialchars($report['reporter_name']); ?><?php echo !empty($report['reported_name']) ? ' against ' . htmlspecialchars($report['reported_name']) : ''; ?></p>
                                </div>
                                <?php if (!empty($report['origin'])): ?>
                                    <strong><?php echo htmlspecialchars($report['origin']); ?> &rarr; <?php echo htmlspecialchars($report['destination']); ?></strong>
                                <?php endif; ?>
                            </div>
                            <p class="admin-message"><?php echo nl2br(htmlspecialchars($report['message'])); ?></p>
                            <?php if (in_array($report['report_status'], ['open', 'reviewing'], true)): ?>
                                <form action="/ridesync/actions/admin_action.php" method="POST" class="admin-report-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action_type" value="report_decision">
                                    <input type="hidden" name="report_id" value="<?php echo (int) $report['id']; ?>">
                                    <input type="hidden" name="return_to" value="/ridesync/pages/admin_dashboard.php?section=reports">
                                    <select name="decision" aria-label="Report decision" required>
                                        <option value="reviewing" <?php echo $report['report_status'] === 'reviewing' ? 'selected' : ''; ?>>Reviewing</option>
                                        <option value="resolved">Resolved</option>
                                        <option value="dismissed">Dismissed</option>
                                    </select>
                                    <input type="text" name="admin_note" maxlength="255" placeholder="Internal note" aria-label="Internal admin note" value="<?php echo htmlspecialchars($report['admin_note'] ?? ''); ?>">
                                    <button type="submit" class="btn btn-primary btn-sm">Update</button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
