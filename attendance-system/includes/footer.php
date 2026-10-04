        </main>
    </div>
</div>

<?php if (($_SESSION['role'] ?? '') === 'student'): $__cur = basename($_SERVER['PHP_SELF']); ?>
<nav class="mobile-bottom-nav role-student">
    <a href="<?php echo BASE_URL; ?>student/dashboard.php" class="<?php echo $__cur === 'dashboard.php' ? 'active' : ''; ?>"><i class="fa-solid fa-house"></i>Home</a>
    <a href="<?php echo BASE_URL; ?>student/scanner.php" class="<?php echo $__cur === 'scanner.php' ? 'active' : ''; ?>"><i class="fa-solid fa-qrcode"></i>Scan</a>
    <a href="<?php echo BASE_URL; ?>student/history.php" class="<?php echo $__cur === 'history.php' ? 'active' : ''; ?>"><i class="fa-solid fa-clock-rotate-left"></i>History</a>
    <a href="<?php echo BASE_URL; ?>student/notifications.php" class="<?php echo $__cur === 'notifications.php' ? 'active' : ''; ?>"><i class="fa-solid fa-bell"></i>Alerts</a>
    <a href="<?php echo BASE_URL; ?>student/profile.php" class="<?php echo $__cur === 'profile.php' ? 'active' : ''; ?>"><i class="fa-solid fa-user"></i>Profile</a>
</nav>
<?php endif; ?>

<script src="<?php echo BASE_URL; ?>assets/js/app.js"></script>
<?php if (!empty($extraScripts)) foreach ($extraScripts as $src): ?>
<script src="<?php echo $src; ?>"></script>
<?php endforeach; ?>
</body>
</html>
