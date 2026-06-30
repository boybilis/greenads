<form method="POST" enctype="multipart/form-data" id="project-form">
    <div class="card-body">
        <div class="row">

        <!-- Project Code -->
        <div class="col-md-6">
            <div class="form-group">
                <label>Project Code</label>
                <input type="text" name="proj_code" class="form-control" placeholder="Auto Generated" readonly>
            </div>
        </div>

        <!-- Project Manager -->
        <div class="col-md-6">
            <div class="form-group">
                <label>Project Manager</label>
                <?php if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Manager') { ?>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($_SESSION['username'] ?? ''); ?>" readonly>
                    <input type="hidden" name="proj_mgr" value="<?= htmlspecialchars($_SESSION['user_code'] ?? ''); ?>">
                <?php } else { ?>
                    <?php
                    $users = $db->getAllRecords("tbl_user", "user_code, user_name", "", "ORDER BY user_name ASC");
                    ?>
                    <select name="proj_mgr" class="form-control" required>
                        <option value="">Select Manager</option>
                        <?php foreach($users as $row): ?>
                            <option value="<?= htmlspecialchars($row['user_code']); ?>">
                                <?= htmlspecialchars($row['user_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php } ?>
            </div>
        </div>

        <!-- Project Name -->
        <div class="col-md-6">
            <div class="form-group">
                <label>Project Name</label>
                <input type="text" name="proj_name" class="form-control" required>
            </div>
        </div>

        <!-- Project Owner -->
        <div class="col-md-6">
            <div class="form-group">
                <label>Project Owner</label>
                <input type="text" name="proj_owner" class="form-control" required>
            </div>
        </div>

        <!-- Project Cost -->
        <div class="col-md-6">
            <div class="form-group">
                <label>Project Cost</label>
                <input type="number" step="0.01" name="proj_cost" class="form-control" required>
            </div>
        </div>

        <!-- Status -->
        <div class="col-md-6">
            <div class="form-group">
                <label>Status</label>
                <select name="proj_status" class="form-control">
                    <option value="0">Ongoing</option>
                    <option value="1">Completed</option>
                </select>
            </div>
        </div>

        <!-- Dates -->
        <div class="col-md-6">
            <div class="form-group">
                <label>Start Date</label>
                <input type="date" name="proj_sd" class="form-control" required>
            </div>
        </div>

        <div class="col-md-6">
            <div class="form-group">
                <label>End Date</label>
                <input type="date" name="proj_ed" class="form-control">
            </div>
        </div>

        <!-- Description -->
        <div class="col-12">
            <div class="form-group">
                <label>Project Description</label>
                <textarea name="proj_desc" class="form-control" rows="3"></textarea>
            </div>
        </div>

        </div>
    </div>

    <div class="card-footer">
        <button type="submit" name="save_project" id="projectSubmitBtn" class="btn btn-primary">Submit</button>
        <button type="button" id="cancelProjectEditBtn" class="btn btn-secondary d-none">Cancel Edit</button>
    </div>
</form>
