# Wordpress-Simple-DB-Backup
Simple database backup scheduler for WordPress

Note: This plugin was developed as a simple solution for automatating scheduled backups of a wordpress database. I utilized ChatGPT to support the development.

---

# Wordpress Simple DB Backup Description

Wordpress Simple DB Backup is a lightweight and robust WordPress plugin designed to automatically backup your database. Schedule backups on an hourly, daily, weekly, or monthly basis and store them as ZIP-compressed files in your uploads directory. The plugin offers an intuitive admin interface for creating multiple backup jobs, triggering immediate backups, and managing your backup logs—all grouped by schedule type for easy organization.

## Features

- **Flexible Scheduling:**  
  Create multiple backup jobs with options for hourly, daily, weekly, or monthly backups. Customize the exact time (and day, if applicable) for each backup job.

- **Immediate Backup:**  
  Trigger an immediate backup at any time with the simple "Backup Now" button.

- **ZIP Compression:**  
  All backups are compressed into ZIP files for efficient storage. Scheduled backup filenames include the backup job label and the date/time, while manual backups are named using a clean timestamp format.

- **Organized Backup Logs:**  
  View and manage your backups through a user-friendly admin panel, with logs automatically grouped by schedule type (hourly, daily, weekly, monthly, and manual).

- **Easy File Management:**  
  Download or delete backup files directly from the admin interface.

- **Automatic Cleanup:**  
  The plugin automatically deletes older backups once the maximum allowed number for each schedule type is reached.

## Installation

1. **Upload the Plugin:**  
   Copy the plugin files to the `/wp-content/plugins/simple-db-backup` directory, or install the plugin via the WordPress plugin installer.

2. **Activate the Plugin:**  
   Navigate to the Plugins section in your WordPress admin and activate "Simple DB Backup".

3. **Setup:**  
   Upon activation, the plugin creates a custom table for backup logs and schedules the necessary cron events.

## Usage

1. **Configure Backup Jobs:**  
   Go to the **DB Backup** menu in the WordPress admin area. Here you can add new backup jobs by specifying:
   - A custom job label.
   - The desired schedule (hourly, daily, weekly, or monthly).
   - The specific time (and day, if applicable) when the backup should run.
   - The maximum number of backups to retain for that job.

2. **Trigger Immediate Backups:**  
   Click the **Backup Now** button to perform an immediate backup.

3. **Manage Your Backups:**  
   View backup logs grouped by schedule type. Download or delete any backup file directly from the interface.

4. **Backup Storage:**  
   All backup ZIP files are saved in the `wp-content/uploads/db-backups/` directory.

## Customization

- **Filename Format:**  
  Scheduled backups use the job label (sanitized) in their filename (e.g., `backup-your-job-label-YYYY-MM-DD_HH-MM-SS.zip`), while manual backups are named `backup-YYYY-MM-DD_HH-MM-SS.zip`.

- **Further Customization:**  
  Feel free to modify the plugin code to suit your specific needs. Contributions and suggestions are welcome!

## Support

If you encounter any issues or have suggestions for improvements, please open an issue on this GitHub repository.

## License

This project is licensed under the [GNU GPLv3 License](LICENSE).

---
