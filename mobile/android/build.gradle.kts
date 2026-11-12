allprojects {
    repositories {
        google()
        mavenCentral()
    }
}

val newBuildDir: Directory =
    rootProject.layout.buildDirectory
        .dir("../../build")
        .get()

rootProject.layout.buildDirectory.value(newBuildDir)

subprojects {
    val newSubprojectBuildDir: Directory = newBuildDir.dir(project.name)
    project.layout.buildDirectory.value(newSubprojectBuildDir)
}

subprojects {
    project.evaluationDependsOn(":app")
}

tasks.register<Delete>("clean") {
    delete(rootProject.layout.buildDirectory)
}

subprojects {
    tasks.withType<org.jetbrains.kotlin.gradle.tasks.KotlinCompile>().configureEach {
        val kotlinTask = this
        val javaTaskName = kotlinTask.name.replace("Kotlin", "JavaWithJavac")
        val javaTask = project.tasks.withType<JavaCompile>().findByName(javaTaskName)
        if (javaTask != null) {
            val targetCompat = javaTask.targetCompatibility
            compilerOptions {
                if (targetCompat == "1.8" || targetCompat == "8" || targetCompat == "VERSION_1_8") {
                    jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_1_8)
                } else if (targetCompat == "11" || targetCompat == "VERSION_11") {
                    jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_11)
                } else if (targetCompat == "17" || targetCompat == "VERSION_17") {
                    jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17)
                } else if (targetCompat == "21" || targetCompat == "VERSION_21") {
                    jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_21)
                }
            }
        } else {
            compilerOptions {
                jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17)
            }
        }
    }
}