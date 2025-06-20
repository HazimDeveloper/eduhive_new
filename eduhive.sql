-- Create database
CREATE DATABASE edu_hive;
USE edu_hive;

-- Users table
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tasks table
CREATE TABLE tasks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    priority VARCHAR(20) DEFAULT 'medium',
    status VARCHAR(20) DEFAULT 'pending',
    due_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Class schedules table
CREATE TABLE class_schedules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    subject VARCHAR(100) NOT NULL,
    day VARCHAR(20) NOT NULL,
    time_start TIME NOT NULL,
    time_end TIME NOT NULL,
    location VARCHAR(100)
);

-- Time tracking table
CREATE TABLE time_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    task_id INT,
    duration INT NOT NULL,
    date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Rewards/gamification table
CREATE TABLE rewards (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    badge_name VARCHAR(50) NOT NULL,
    points INT DEFAULT 0,
    earned_date DATE DEFAULT (CURRENT_DATE)
);

-- Tambah indeks untuk prestasi
ALTER TABLE tasks
ADD INDEX idx_user_status (user_id, status);