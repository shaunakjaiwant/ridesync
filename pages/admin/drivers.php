<section class="admin-command-card admin-table-card" id="drivers">
            <div class="admin-card-head">
                <div>
                    <span class="driver-kicker">Fleet</span>
                    <h2>Drivers</h2>
                </div>
                <div class="admin-table-tools">
                    <input type="search" placeholder="Filter drivers" aria-label="Filter drivers table" data-admin-table-search="driversTable" data-search-context="driversTable">
                    <select aria-label="Filter drivers by status" data-admin-table-status="driversTable">
                        <option value="">All statuses</option>
                        <option value="pending">Pending</option>
                        <option value="verified">Verified</option>
                        <option value="suspicious">Suspicious</option>
                        <option value="manual_review">Needs Review</option>
                        <option value="rejected">Rejected</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
            </div>

            <div class="admin-kyc-queue" aria-label="Driver verification queue">
                <div><span>Pending</span><strong><?php echo (int) $aiQueueMetrics['pending']; ?></strong></div>
                <div><span>AI Verified</span><strong><?php echo (int) $aiQueueMetrics['verified']; ?></strong></div>
                <div><span>Suspicious</span><strong><?php echo (int) $aiQueueMetrics['suspicious']; ?></strong></div>
                <div><span>Rejected</span><strong><?php echo (int) $aiQueueMetrics['rejected']; ?></strong></div>
                <div><span>Needs Review</span><strong><?php echo (int) $aiQueueMetrics['needs_review']; ?></strong></div>
            </div>

            <div class="admin-table-wrap">
                <table class="admin-smart-table" id="driversTable">
                    <thead>
                        <tr>
                            <th>Driver</th>
                            <th>Trust</th>
                            <th>AI Decision</th>
                            <th>Docs</th>
                            <th>Availability</th>
                            <th>Vehicle</th>
                            <th>Activity</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($driverRows as $driver): ?>
                            <?php
                            $profileStatus = $driver['profile_status'] ?: 'pending';
                            $docSummary = ridesync_admin_required_doc_summary($driver);
                            $driverPanelReady = $profileStatus === 'verified'
                                && $docSummary['ready']
                                && $driver['account_status'] === 'active';
                            $canApproveReadyDriver = !empty($driver['profile_id'])
                                && $docSummary['complete']
                                && !$driverPanelReady;
                            $aiStatus = $driver['ai_decision'] ?: ($driver['ai_status'] ?: 'not_run');
                            $driverSearch = ridesync_admin_search_blob([$driver['name'], $driver['email'], $driver['phone'], $driver['vehicle_number'], $profileStatus, $driver['account_status'], $aiStatus, $driver['ai_risk_level']]);
                            $driverDetailUrl = '/ridesync/pages/admin_driver_verification.php?driver_id=' . (int) $driver['driver_id'];
                            $driverLinks = [
                                ['label' => 'Open verification page', 'href' => $driverDetailUrl],
                                ['label' => 'Filter driver requests', 'href' => ridesync_admin_section_url('requests', $driver['email'])],
                            ];
                            if (!empty($driver['linked_user_id'])) {
                                $driverLinks[] = ['label' => 'Open linked rider', 'href' => '/ridesync/pages/admin_user_detail.php?user_id=' . (int) $driver['linked_user_id']];
                            }
                            ?>
                            <tr data-search="<?php echo htmlspecialchars($driverSearch); ?>" data-status="<?php echo htmlspecialchars($profileStatus . ' ' . $driver['account_status'] . ' ' . $aiStatus); ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($driver['name']); ?></strong>
                                    <span><?php echo htmlspecialchars($driver['email']); ?></span>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_status_class($profileStatus)); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($profileStatus)); ?></span>
                                    <small><?php echo htmlspecialchars(ridesync_admin_status_label($driver['account_status'])); ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($driver['ai_session_id'])): ?>
                                        <span class="badge badge-<?php echo htmlspecialchars(ridesync_verification_badge_class($aiStatus)); ?>">
                                            <?php echo htmlspecialchars(ridesync_verification_status_label($aiStatus)); ?>
                                        </span>
                                        <small><?php echo (int) round((float) $driver['ai_confidence_score']); ?> score, <?php echo htmlspecialchars(ucfirst((string) $driver['ai_risk_level'])); ?> risk</small>
                                    <?php else: ?>
                                        <span class="badge badge-closed">Not Run</span>
                                        <small>Awaiting analysis</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($docSummary['label']); ?></strong>
                                    <span><?php echo (int) $driver['pending_documents']; ?> pending, <?php echo (int) $driver['total_documents']; ?> total</span>
                                </td>
                                <td>
                                    <span class="admin-status-dot is-<?php echo htmlspecialchars($driver['availability_status']); ?>"></span>
                                    <?php echo htmlspecialchars(ridesync_admin_status_label($driver['availability_status'])); ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($driver['vehicle_number'] ?: 'Not submitted'); ?></strong>
                                    <span><?php echo htmlspecialchars(($driver['vehicle_type'] ?: 'Vehicle') . ' - ' . ((int) $driver['seating_capacity']) . ' seats'); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo (int) $driver['completed_trips']; ?> trips</strong>
                                    <span><?php echo (int) $driver['pending_requests']; ?> pending, <?php echo (int) $driver['assigned_rides']; ?> assigned</span>
                                </td>
                                <td>
                                    <div class="admin-row-actions">
                                        <a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars($driverDetailUrl); ?>">Docs</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($driverRows) === 0): ?>
                            <tr><td colspan="8" class="admin-table-empty">No drivers found on this page.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
