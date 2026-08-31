-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 01, 2026 at 12:40 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `cse370_project`
--

-- --------------------------------------------------------

--
-- Table structure for table `anonymous_profile`
--

CREATE TABLE `anonymous_profile` (
  `anon_id` int(11) NOT NULL,
  `profile_id` int(11) NOT NULL,
  `pseudonym` varchar(100) NOT NULL,
  `hobbies` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `is_banned` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `anonymous_profile`
--

INSERT INTO `anonymous_profile` (`anon_id`, `profile_id`, `pseudonym`, `hobbies`, `created_at`, `is_banned`) VALUES
(1, 2, 'Night owl', 'Books, anime', '2026-08-22 19:40:48', 0);

-- --------------------------------------------------------

--
-- Table structure for table `bracu_course`
--

CREATE TABLE `bracu_course` (
  `course_code` varchar(10) NOT NULL,
  `course_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bracu_course`
--

INSERT INTO `bracu_course` (`course_code`, `course_name`) VALUES
('ARC 101', 'Design I'),
('BUS 101', 'Introduction to Business'),
('CSE 110', 'Programming Language I'),
('CSE 111', 'Programming Language II'),
('CSE 220', 'Data Structures'),
('CSE 221', 'Algorithms'),
('CSE 230', 'Discrete Mathematics'),
('CSE 260', 'Digital Logic Design'),
('CSE 320', 'Data Communications'),
('CSE 330', 'Numerical Methods'),
('CSE 370', 'Database Systems'),
('CSE 420', 'Compiler Design'),
('ECO 101', 'Introduction to Microeconomics'),
('EEE 201', 'Electrical Circuits I'),
('EEE 205', 'Electronic Devices and Circuits I'),
('ENG 101', 'Fundamentals of English'),
('ENG 102', 'Composition I'),
('MAT 110', 'Differential Calculus and Coordinate Geometry'),
('MAT 120', 'Integral Calculus and Differential Equations'),
('MAT 215', 'Math for Machine Learning'),
('MAT 216', 'Linear Algebra'),
('PHR 101', 'Introduction to Pharmacy'),
('PHY 111', 'Principles of Physics I'),
('PHY 112', 'Principles of Physics II');

-- --------------------------------------------------------

--
-- Table structure for table `favorwallet`
--

CREATE TABLE `favorwallet` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `type` varchar(100) NOT NULL,
  `number` int(11) NOT NULL DEFAULT 0,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `favorwallet`
--

INSERT INTO `favorwallet` (`id`, `student_id`, `name`, `type`, `number`, `description`) VALUES
(1, 2, 'arnab', 'calculator', 1, 'nothing');

-- --------------------------------------------------------

--
-- Table structure for table `report_log`
--

CREATE TABLE `report_log` (
  `report_id` int(11) NOT NULL,
  `reported_user_id` int(11) NOT NULL,
  `reporter_user_id` int(11) NOT NULL,
  `room_or_group_id` int(11) NOT NULL,
  `platform` varchar(20) NOT NULL COMMENT '"Stranger" or "Study"',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `resource_upload`
--

CREATE TABLE `resource_upload` (
  `resource_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `links` text NOT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `room_member`
--

CREATE TABLE `room_member` (
  `room_id` int(11) NOT NULL,
  `anon_id` int(11) NOT NULL,
  `joined_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `room_member`
--

INSERT INTO `room_member` (`room_id`, `anon_id`, `joined_at`) VALUES
(1, 1, '2026-08-22 19:41:12'),
(2, 1, '2026-08-25 15:04:04'),
(8, 1, '2026-09-01 04:36:58');

-- --------------------------------------------------------

--
-- Table structure for table `room_message`
--

CREATE TABLE `room_message` (
  `message_id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `anon_id` int(11) NOT NULL,
  `content` text NOT NULL,
  `sent_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `room_message`
--

INSERT INTO `room_message` (`message_id`, `room_id`, `anon_id`, `content`, `sent_at`) VALUES
(1, 1, 1, 'hii', '2026-08-22 19:41:53');

-- --------------------------------------------------------

--
-- Table structure for table `stranger_room`
--

CREATE TABLE `stranger_room` (
  `room_id` int(11) NOT NULL,
  `room_name` varchar(150) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1,
  `is_random` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stranger_room`
--

INSERT INTO `stranger_room` (`room_id`, `room_name`, `created_by`, `created_at`, `is_active`, `is_random`) VALUES
(1, 'anime night', 1, '2026-08-22 19:41:12', 1, 0),
(2, 'skfhkhf', 1, '2026-08-25 15:04:04', 1, 0),
(8, 'sdhkajfhk', 1, '2026-09-01 04:36:58', 1, 0);

-- --------------------------------------------------------

--
-- Table structure for table `student`
--

CREATE TABLE `student` (
  `profile_id` int(11) NOT NULL,
  `id` varchar(50) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `dept` varchar(100) NOT NULL,
  `is_banned` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student`
--

INSERT INTO `student` (`profile_id`, `id`, `email`, `password`, `name`, `dept`, `is_banned`) VALUES
(2, '24101055', 'arnab.sen@g.bracu.ac.bd', '$2y$10$bZ028YTEpe/CBjXnrGKDJ.aya6UG.cvzapucgsMVKkRsgSIsP3bVe', 'ARNAB SEN', 'CSE', 0),
(3, '24101251', 'abdul.kadir.abir@g.bracu.ac.bd', '$2y$10$OHOVXomANAr0rfRMTiI0f.7jIZF/mOSX7whJaDCPvF550dhdy7nzW', 'Abdul Kadir Abir', 'CSE', 0);

-- --------------------------------------------------------

--
-- Table structure for table `student_mobile`
--

CREATE TABLE `student_mobile` (
  `mobile_id` int(11) NOT NULL,
  `profile_id` int(11) NOT NULL,
  `mobile` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_mobile`
--

INSERT INTO `student_mobile` (`mobile_id`, `profile_id`, `mobile`) VALUES
(2, 2, '+8801633162140'),
(3, 3, '01533518326');

-- --------------------------------------------------------

--
-- Table structure for table `study_group`
--

CREATE TABLE `study_group` (
  `group_id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `topic` varchar(200) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `study_group`
--

INSERT INTO `study_group` (`group_id`, `room_id`, `description`, `topic`, `created_at`) VALUES
(1, 1, 'coding', 'algo', '2026-08-25 13:20:43');

-- --------------------------------------------------------

--
-- Table structure for table `study_group_member`
--

CREATE TABLE `study_group_member` (
  `group_id` int(11) NOT NULL,
  `profile_id` int(11) NOT NULL,
  `joined_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `study_group_member`
--

INSERT INTO `study_group_member` (`group_id`, `profile_id`, `joined_at`) VALUES
(1, 2, '2026-08-25 13:20:43');

-- --------------------------------------------------------

--
-- Table structure for table `study_room`
--

CREATE TABLE `study_room` (
  `room_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `study_room`
--

INSERT INTO `study_room` (`room_id`, `title`, `created_by`, `created_at`) VALUES
(1, 'cse 221', 2, '2026-08-25 13:20:43');

-- --------------------------------------------------------

--
-- Table structure for table `weekly_favor_log`
--

CREATE TABLE `weekly_favor_log` (
  `id` int(11) NOT NULL,
  `favorwallet_id` int(11) NOT NULL,
  `weekly_number` int(11) NOT NULL,
  `activity_record` varchar(255) NOT NULL,
  `activity_count` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `anonymous_profile`
--
ALTER TABLE `anonymous_profile`
  ADD PRIMARY KEY (`anon_id`),
  ADD UNIQUE KEY `profile_id` (`profile_id`);

--
-- Indexes for table `bracu_course`
--
ALTER TABLE `bracu_course`
  ADD PRIMARY KEY (`course_code`);

--
-- Indexes for table `favorwallet`
--
ALTER TABLE `favorwallet`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_id` (`student_id`);

--
-- Indexes for table `report_log`
--
ALTER TABLE `report_log`
  ADD PRIMARY KEY (`report_id`),
  ADD UNIQUE KEY `unique_report` (`reported_user_id`,`reporter_user_id`,`room_or_group_id`,`platform`),
  ADD KEY `reporter_user_id` (`reporter_user_id`);

--
-- Indexes for table `resource_upload`
--
ALTER TABLE `resource_upload`
  ADD PRIMARY KEY (`resource_id`),
  ADD KEY `group_id` (`group_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `room_member`
--
ALTER TABLE `room_member`
  ADD PRIMARY KEY (`room_id`,`anon_id`),
  ADD KEY `anon_id` (`anon_id`);

--
-- Indexes for table `room_message`
--
ALTER TABLE `room_message`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `room_id` (`room_id`),
  ADD KEY `anon_id` (`anon_id`);

--
-- Indexes for table `stranger_room`
--
ALTER TABLE `stranger_room`
  ADD PRIMARY KEY (`room_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `student`
--
ALTER TABLE `student`
  ADD PRIMARY KEY (`profile_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `student_mobile`
--
ALTER TABLE `student_mobile`
  ADD PRIMARY KEY (`mobile_id`),
  ADD KEY `profile_id` (`profile_id`);

--
-- Indexes for table `study_group`
--
ALTER TABLE `study_group`
  ADD PRIMARY KEY (`group_id`),
  ADD KEY `room_id` (`room_id`);

--
-- Indexes for table `study_group_member`
--
ALTER TABLE `study_group_member`
  ADD PRIMARY KEY (`group_id`,`profile_id`),
  ADD KEY `profile_id` (`profile_id`);

--
-- Indexes for table `study_room`
--
ALTER TABLE `study_room`
  ADD PRIMARY KEY (`room_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `weekly_favor_log`
--
ALTER TABLE `weekly_favor_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `favorwallet_id` (`favorwallet_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `anonymous_profile`
--
ALTER TABLE `anonymous_profile`
  MODIFY `anon_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `favorwallet`
--
ALTER TABLE `favorwallet`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `report_log`
--
ALTER TABLE `report_log`
  MODIFY `report_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `resource_upload`
--
ALTER TABLE `resource_upload`
  MODIFY `resource_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `room_message`
--
ALTER TABLE `room_message`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `stranger_room`
--
ALTER TABLE `stranger_room`
  MODIFY `room_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `student`
--
ALTER TABLE `student`
  MODIFY `profile_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `student_mobile`
--
ALTER TABLE `student_mobile`
  MODIFY `mobile_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `study_group`
--
ALTER TABLE `study_group`
  MODIFY `group_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `study_room`
--
ALTER TABLE `study_room`
  MODIFY `room_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `weekly_favor_log`
--
ALTER TABLE `weekly_favor_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `anonymous_profile`
--
ALTER TABLE `anonymous_profile`
  ADD CONSTRAINT `anonymous_profile_ibfk_1` FOREIGN KEY (`profile_id`) REFERENCES `student` (`profile_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `favorwallet`
--
ALTER TABLE `favorwallet`
  ADD CONSTRAINT `favorwallet_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `student` (`profile_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `report_log`
--
ALTER TABLE `report_log`
  ADD CONSTRAINT `report_log_ibfk_1` FOREIGN KEY (`reported_user_id`) REFERENCES `student` (`profile_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `report_log_ibfk_2` FOREIGN KEY (`reporter_user_id`) REFERENCES `student` (`profile_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `resource_upload`
--
ALTER TABLE `resource_upload`
  ADD CONSTRAINT `resource_upload_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `study_group` (`group_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `resource_upload_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `student` (`profile_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `room_member`
--
ALTER TABLE `room_member`
  ADD CONSTRAINT `room_member_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `stranger_room` (`room_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `room_member_ibfk_2` FOREIGN KEY (`anon_id`) REFERENCES `anonymous_profile` (`anon_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `room_message`
--
ALTER TABLE `room_message`
  ADD CONSTRAINT `room_message_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `stranger_room` (`room_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `room_message_ibfk_2` FOREIGN KEY (`anon_id`) REFERENCES `anonymous_profile` (`anon_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `stranger_room`
--
ALTER TABLE `stranger_room`
  ADD CONSTRAINT `stranger_room_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `anonymous_profile` (`anon_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `student_mobile`
--
ALTER TABLE `student_mobile`
  ADD CONSTRAINT `student_mobile_ibfk_1` FOREIGN KEY (`profile_id`) REFERENCES `student` (`profile_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `study_group`
--
ALTER TABLE `study_group`
  ADD CONSTRAINT `study_group_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `study_room` (`room_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `study_group_member`
--
ALTER TABLE `study_group_member`
  ADD CONSTRAINT `study_group_member_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `study_group` (`group_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `study_group_member_ibfk_2` FOREIGN KEY (`profile_id`) REFERENCES `student` (`profile_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `study_room`
--
ALTER TABLE `study_room`
  ADD CONSTRAINT `study_room_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `student` (`profile_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `weekly_favor_log`
--
ALTER TABLE `weekly_favor_log`
  ADD CONSTRAINT `weekly_favor_log_ibfk_1` FOREIGN KEY (`favorwallet_id`) REFERENCES `favorwallet` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
